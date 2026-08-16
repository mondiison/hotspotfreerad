<?php

namespace App\Services;

use App\Models\Router;

class ZeroTierMembershipSyncService
{
    public function __construct(private readonly ZeroTierControllerService $controller) {}

    /**
     * Authorize every router that has a known ZeroTier node ID but isn't yet
     * authorized on the self-hosted controller.
     *
     * Unlike WireGuard, the app can't pre-authorize a router before it ever
     * connects -- a router generates its own ZeroTier node identity locally,
     * the first time `/zerotier enable` runs, and there's no way to predict
     * it in advance. So this only ever authorizes nodes this app already
     * knows about (`Router::zerotier_node_id`, entered manually or fetched
     * over a still-working WireGuard link -- see docs/zerotier-fallback-setup.md).
     * Any node the controller reports that this app can't attribute to a
     * router is surfaced in `unmatched_members` instead of silently ignored,
     * so an admin has something concrete to act on rather than a mystery.
     *
     * @return array{enabled: bool, desired: array<string,string>, authorized: array<string,string>, errors: array<int,string>, unmatched_members: array<int,string>}
     */
    public function reconcile(bool $dryRun = false): array
    {
        $enabled = (bool) config('services.zerotier.manage_members', false);

        $result = [
            'enabled' => $enabled,
            'desired' => $this->desiredNodes(),
            'authorized' => [],
            'errors' => [],
            'unmatched_members' => [],
        ];

        if (! $enabled) {
            return $result;
        }

        $listing = $this->controller->listMembers();

        if (! $listing['success']) {
            $result['errors'][] = 'Could not list ZeroTier controller members: '.$listing['error'];

            return $result;
        }

        $members = collect($listing['members'] ?? [])->keyBy('node_id');
        $desiredNodeIds = array_keys($result['desired']);

        $result['unmatched_members'] = $members->keys()
            ->reject(fn (string $nodeId): bool => in_array($nodeId, $desiredNodeIds, true))
            ->values()
            ->all();

        foreach ($this->desiredRouters() as $router) {
            $alreadyAuthorized = (bool) ($members[$router->zerotier_node_id]['authorized'] ?? false);

            if ($alreadyAuthorized && $router->zerotier_authorized_at !== null) {
                continue;
            }

            if ($dryRun) {
                $result['authorized'][$router->zerotier_node_id] = (string) $router->zerotier_ip;

                continue;
            }

            $authorize = $this->controller->authorizeMember($router);

            if (! $authorize['success']) {
                $result['errors'][] = "Failed to authorize router \"{$router->name}\" (node {$router->zerotier_node_id}): ".$authorize['error'];

                continue;
            }

            $router->forceFill(['zerotier_authorized_at' => now()])->save();
            $result['authorized'][$router->zerotier_node_id] = (string) $router->zerotier_ip;
        }

        return $result;
    }

    /**
     * @return array<string,string> ZeroTier node ID => assigned IP
     */
    public function desiredNodes(): array
    {
        return $this->desiredRouters()->pluck('zerotier_ip', 'zerotier_node_id')->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Router>
     */
    private function desiredRouters(): \Illuminate\Database\Eloquent\Collection
    {
        return Router::query()
            ->whereNotNull('zerotier_node_id')
            ->whereIn('tunnel_mode', ['wireguard_zerotier', 'zerotier'])
            ->get();
    }
}
