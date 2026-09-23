<?php

namespace App\Support;

use App\Models\Router;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RadiusAccountingStats
{
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

    public function refreshRouterHealth(EloquentCollection $routers): EloquentCollection
    {
        if (! $this->hasAccounting() || $routers->isEmpty()) {
            $routers->each(fn (Router $router) => $router->setAttribute('detected_status', 'Accounting unavailable'));

            return $routers;
        }

        $routers->each(function (Router $router): void {
            $nasIps = $this->nasIpsFor($router);

            $latestSession = DB::table('radacct')
                ->whereIn('nasipaddress', $nasIps)
                ->orderByRaw('COALESCE(acctupdatetime, acctstarttime) desc')
                ->first();

            $lastSeenAt = $latestSession?->acctupdatetime ?? $latestSession?->acctstarttime;
            $hasActiveSession = DB::table('radacct')
                ->whereIn('nasipaddress', $nasIps)
                ->whereNull('acctstoptime')
                ->exists();
            if (! $latestSession) {
                $router->setAttribute('detected_status', 'No accounting yet');

                return;
            }

            $isRecentlySeen = $lastSeenAt && now()->parse($lastSeenAt)->greaterThan(now()->subMinutes(10));
            $isOnline = $hasActiveSession;
            $detectedStatus = match (true) {
                $hasActiveSession => 'Online',
                $isRecentlySeen => 'Recently seen',
                default => 'Idle / no recent sessions',
            };

            $router->forceFill([
                'is_online' => $isOnline || $isRecentlySeen,
                'last_seen_at' => $lastSeenAt ?: $router->last_seen_at,
            ])->save();
            $router->setAttribute('detected_status', $detectedStatus);
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
