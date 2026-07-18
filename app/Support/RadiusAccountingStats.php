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

    public function refreshRouterHealth(EloquentCollection $routers): EloquentCollection
    {
        if (! $this->hasAccounting() || $routers->isEmpty()) {
            $routers->each(fn (Router $router) => $router->setAttribute('detected_status', 'Accounting unavailable'));

            return $routers;
        }

        $routers->each(function (Router $router): void {
            $latestSession = DB::table('radacct')
                ->where('nasipaddress', $router->wireguard_internal_ip)
                ->orderByRaw('COALESCE(acctupdatetime, acctstarttime) desc')
                ->first();

            $lastSeenAt = $latestSession?->acctupdatetime ?? $latestSession?->acctstarttime;
            $hasActiveSession = DB::table('radacct')
                ->where('nasipaddress', $router->wireguard_internal_ip)
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
                $router = $routers->firstWhere('wireguard_internal_ip', $session->nasipaddress);
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

        return DB::table('radacct')->whereIn('nasipaddress', $routers->pluck('wireguard_internal_ip')->all());
    }
}
