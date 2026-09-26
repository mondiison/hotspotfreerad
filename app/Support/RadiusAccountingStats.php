<?php

namespace App\Support;

use App\Models\Router;
use App\Models\RouterMetricSample;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RadiusAccountingStats
{
    /**
     * A router's own "does it get scheduled a fresh /hotspot:sample-router-metrics
     * pass" cadence is 5 minutes -- this gives one full missed cycle of
     * slack before a stale-but-present heartbeat sample stops counting as
     * "currently reachable," the same reasoning behind the 10-minute
     * "recently seen" window below for accounting activity.
     */
    private const HEARTBEAT_FRESHNESS_MINUTES = 15;

    public function hasAccounting(): bool
    {
        return Schema::hasTable('radacct');
    }

    /**
     * Confirmed live 2026-09-23: a ZeroTier-only (or dual-mode) router's
     * actual RADIUS traffic can arrive at FreeRADIUS from its ZeroTier IP
     * rather than wireguard_internal_ip -- RadiusProvisioningService::
     * syncRouter() already accounts for this by writing a second `nas` row
     * keyed to zerotier_ip, but this class only ever matched `radacct.
     * nasipaddress` against wireguard_internal_ip, so a router that never
     * actually sends traffic over WireGuard (tunnel_mode=zerotier) showed
     * "No accounting yet"/"Last seen never" permanently regardless of real
     * activity, since its accounting rows are keyed to an IP this class
     * never checked. Every method below now matches against both possible
     * IPs, the same dual-IP awareness `syncRadiusClients()`/
     * `apiServiceAddressRestriction()` already have elsewhere in this
     * codebase for exactly this "which IP did this router actually use"
     * concern.
     *
     * @return list<string>
     */
    private function nasIpsFor(Router $router): array
    {
        return array_values(array_filter([$router->wireguard_internal_ip, $router->zerotier_ip]));
    }

    /**
     * "Online" used to mean exactly one thing: an active (still-open)
     * `radacct` session, i.e. at least one customer currently authenticated
     * through that router. Confirmed live 2026-09-27 as a real, if obvious
     * once named, accuracy gap: a router that's fully healthy and reachable
     * but simply has zero customers connected right now (overnight, a new
     * site with no traffic yet, etc.) showed as "Idle"/offline on the
     * dashboard exactly like a router that's actually down -- the two are
     * completely different conditions the old check couldn't distinguish.
     *
     * This app already runs a genuine per-router heartbeat independent of
     * customer traffic -- `hotspot:sample-router-metrics` (`RouterMetricSamplingService`)
     * pings every router every 5 minutes over ICMP regardless of whether
     * anyone is using it, the same signal `RouterAlertNotification`'s
     * offline/online alerts already fire from. "Online" is now `heartbeat
     * reachable` OR `has an active accounting session` -- an OR, not a
     * replacement, since a genuinely active customer session is still
     * unambiguous proof of life even in the rare case ICMP itself is
     * firewalled off while RADIUS/data traffic keeps flowing fine.
     * `last_seen_at` similarly becomes the more recent of "last accounting
     * activity" and "last heartbeat sample," so a router with no customers
     * yet still shows a real, current "last seen" instead of "Never."
     */
    public function refreshRouterHealth(EloquentCollection $routers): EloquentCollection
    {
        if ($routers->isEmpty()) {
            return $routers;
        }

        $accountingReady = $this->hasAccounting();
        $latestSamples = RouterMetricSample::query()
            ->whereIn('router_id', $routers->pluck('id'))
            ->orderByDesc('sampled_at')
            ->get()
            ->groupBy('router_id')
            ->map(fn (Collection $samples) => $samples->first());

        $routers->each(function (Router $router) use ($accountingReady, $latestSamples): void {
            $sample = $latestSamples->get($router->id);
            $heartbeatIsFresh = $sample && $sample->sampled_at->greaterThan(now()->subMinutes(self::HEARTBEAT_FRESHNESS_MINUTES));
            $heartbeatIsReachable = $heartbeatIsFresh && $sample->latency_ms !== null;

            $hasActiveSession = false;
            $accountingLastSeenAt = null;

            if ($accountingReady) {
                $nasIps = $this->nasIpsFor($router);
                $latestSession = DB::table('radacct')
                    ->whereIn('nasipaddress', $nasIps)
                    ->orderByRaw('COALESCE(acctupdatetime, acctstarttime) desc')
                    ->first();

                $accountingLastSeenAt = $latestSession?->acctupdatetime ?? $latestSession?->acctstarttime;
                $hasActiveSession = DB::table('radacct')
                    ->whereIn('nasipaddress', $nasIps)
                    ->whereNull('acctstoptime')
                    ->exists();
            }

            $lastSeenCandidates = array_filter([
                $accountingLastSeenAt ? now()->parse($accountingLastSeenAt) : null,
                $sample?->sampled_at,
            ]);
            $lastSeenAt = $lastSeenCandidates === [] ? null : max($lastSeenCandidates);

            $isRecentlySeen = $lastSeenAt && $lastSeenAt->greaterThan(now()->subMinutes(10));
            $isOnline = $heartbeatIsReachable || $hasActiveSession;

            $detectedStatus = match (true) {
                $isOnline => 'Online',
                $isRecentlySeen => 'Recently seen',
                $lastSeenAt !== null => 'Idle / no recent sessions',
                $accountingReady => 'No data yet',
                default => 'Monitoring unavailable',
            };

            $router->forceFill([
                'is_online' => $isOnline || $isRecentlySeen,
                'last_seen_at' => $lastSeenAt ?: $router->last_seen_at,
            ])->save();
            $router->setAttribute('detected_status', $detectedStatus);
            $router->setAttribute('heartbeat_latency_ms', $sample?->latency_ms);
        });

        return $routers;
    }

    public function summary(EloquentCollection $routers): array
    {
        $query = $this->queryForRouters($routers);

        if (! $query) {
            return [
                'ready' => false,
                'active_session_count' => null,
                'online_user_count' => null,
                'total_bytes' => 0,
                'today_bytes' => 0,
            ];
        }

        return [
            'ready' => true,
            'active_session_count' => (clone $query)->whereNull('acctstoptime')->count(),
            'online_user_count' => (clone $query)->whereNull('acctstoptime')->distinct('username')->count('username'),
            'total_bytes' => (int) (clone $query)->sum(DB::raw('COALESCE(acctinputoctets, 0) + COALESCE(acctoutputoctets, 0)')),
            'today_bytes' => (int) (clone $query)
                ->where('acctstarttime', '>=', now()->startOfDay())
                ->sum(DB::raw('COALESCE(acctinputoctets, 0) + COALESCE(acctoutputoctets, 0)')),
        ];
    }

    public function onlineSessions(EloquentCollection $routers, int $limit = 6): Collection
    {
        $query = $this->queryForRouters($routers);

        if (! $query) {
            return collect();
        }

        return $query
            ->whereNull('acctstoptime')
            ->orderByRaw('COALESCE(acctupdatetime, acctstarttime) desc')
            ->limit($limit)
            ->get()
            ->map(function ($session) use ($routers) {
                $router = $routers->first(fn (Router $router): bool => in_array($session->nasipaddress, $this->nasIpsFor($router), true));
                $session->router_name = $router?->name ?? $session->nasipaddress;
                $session->shop_name = $router?->shop?->name;
                $session->total_bytes = (int) ($session->acctinputoctets ?? 0) + (int) ($session->acctoutputoctets ?? 0);

                return $session;
            });
    }

    private function queryForRouters(EloquentCollection $routers)
    {
        if (! $this->hasAccounting() || $routers->isEmpty()) {
            return null;
        }

        $nasIps = $routers->flatMap(fn (Router $router): array => $this->nasIpsFor($router))->unique()->values()->all();

        return DB::table('radacct')->whereIn('nasipaddress', $nasIps);
    }
}
