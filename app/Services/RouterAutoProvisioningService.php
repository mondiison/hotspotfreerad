<?php

namespace App\Services;

use App\Models\Router;

/**
 * Closes the gap between "paste the bootstrap script" and "push the rest of
 * the config" without an admin having to come back and click a button.
 * `provisionHotspot()`/`provisionPppoe()` are already fully idempotent
 * (syncRadiusClients()/syncWalledGarden()/syncZeroTierNetworkMembership()/
 * syncApiServiceRestriction() all list-then-diff before writing anything),
 * so simply re-attempting them on a schedule until they succeed is safe --
 * no separate reachability probe is needed first, since those methods
 * already fail fast and cleanly against an unreachable router.
 *
 * Mirrors WireGuardPeerSyncService/ZeroTierMembershipSyncService/
 * WireGuardRouteSyncService's exact shape: a reconcile() method gated by
 * its own config flag, safe to run on a schedule, safe to call with no
 * effect when disabled.
 */
class RouterAutoProvisioningService
{
    public function __construct(private readonly RouterOsConnectionService $routerOs) {}

    /**
     * @return array{enabled: bool, provisioned: list<string>, pending: list<string>, errors: list<string>}
     */
    public function reconcile(bool $dryRun = false): array
    {
        $enabled = (bool) config('services.mikrotik.auto_provision_routers', false);

        $result = ['enabled' => $enabled, 'provisioned' => [], 'pending' => [], 'errors' => []];

        if (! $enabled) {
            return $result;
        }

        foreach ($this->pendingRouters() as $router) {
            if ($dryRun) {
                $this->previewRouter($router, $result);

                continue;
            }

            $this->provisionRouter($router, $result);
        }

        return $result;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Router>
     */
    private function pendingRouters(): \Illuminate\Database\Eloquent\Collection
    {
        return Router::query()
            ->whereNotNull('api_username')
            ->whereNull('auto_provisioned_at')
            ->get();
    }

    /**
     * @param  array{provisioned: list<string>, pending: list<string>}  $result
     */
    private function previewRouter(Router $router, array &$result): void
    {
        $test = $this->routerOs->testConnection($router);

        if ($test['success']) {
            $result['provisioned'][] = $router->name;
        } else {
            $result['pending'][] = $router->name;
        }
    }

    /**
     * @param  array{provisioned: list<string>, pending: list<string>, errors: list<string>}  $result
     */
    private function provisionRouter(Router $router, array &$result): void
    {
        $needsPppoe = (bool) data_get($router->provisioning_settings, 'enable_pppoe');

        $hotspotResult = $this->routerOs->provisionHotspot($router);
        $pppoeResult = $needsPppoe ? $this->routerOs->provisionPppoe($router) : ['success' => true, 'steps' => []];

        if ($hotspotResult['success'] && $pppoeResult['success']) {
            $router->forceFill(['auto_provisioned_at' => now()])->save();
            $result['provisioned'][] = $router->name;

            return;
        }

        $result['pending'][] = $router->name;

        // A router that's simply not reachable yet reports every step failing
        // with the same connection error, repeated on every 5-minute retry
        // until it comes online -- noisy but harmless and already an accepted
        // tradeoff elsewhere in this app (see provisionHotspot()/provisionPppoe()'s
        // own docblock). No attempt is made here to distinguish "still offline"
        // from "reachable but a step genuinely failed" by inspecting error text --
        // that kind of string-matching has been a real source of bugs in this
        // codebase before, so every failed step is just reported plainly.
        foreach (array_merge($hotspotResult['steps'], $pppoeResult['steps']) as $step) {
            if (! $step['success'] && $step['error'] !== null) {
                $result['errors'][] = "{$router->name}: {$step['label']} - {$step['error']}";
            }
        }
    }
}
