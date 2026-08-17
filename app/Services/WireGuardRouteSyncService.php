<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Process;

class WireGuardRouteSyncService
{
    /**
     * Reconcile the Pi's kernel routing table against routers with
     * `provisioning_settings.route_lan_through_tunnel` enabled.
     *
     * Widening a WireGuard peer's AllowedIPs (WireGuardPeerSyncService) is only half
     * of what's needed to route a router's mgmt/staff VLAN through the tunnel --
     * `wg set` (runtime peer updates) does not create a kernel route the way
     * `wg-quick up` would at startup from `[Peer]` blocks in the interface config
     * file. This Pi's wg-saas.conf has no `[Peer]` blocks at all (every peer is
     * added purely at runtime), so a subnet outside the WireGuard interface's own
     * directly-connected range (10.8.0.0/24) needs an explicit `ip route` too, or
     * the Pi's own outbound traffic (e.g. a RADIUS Access-Accept response) never
     * reaches it.
     *
     * Every route this service ever adds is tagged with a dedicated `proto` name
     * (`services.wireguard.route_proto`, registered once in /etc/iproute2/rt_protos
     * -- see docs/wireguard-server-setup.md), so reconciliation only ever reads/
     * writes routes carrying that tag (`ip route show proto <name>`) and can safely
     * both add AND remove routes without any risk of touching the interface's own
     * connected route or anything an admin added by hand -- unlike
     * WireGuardPeerSyncService, which deliberately never removes a peer for
     * exactly that safety reason, this can safely reconcile in both directions
     * because the proto tag makes "ours" unambiguous.
     *
     * @return array{enabled: bool, interface: string, binary_available: bool, desired: list<string>, current: list<string>, added: list<string>, removed: list<string>, errors: array<int,string>}
     */
    public function reconcile(bool $dryRun = false): array
    {
        $interface = (string) config('services.wireguard.interface', 'wg-saas');
        $proto = (string) config('services.wireguard.route_proto', 'hotspotfreerad');
        $enabled = (bool) config('services.wireguard.manage_routes', false);

        $result = [
            'enabled' => $enabled,
            'interface' => $interface,
            'binary_available' => false,
            'desired' => $this->desiredRoutes(),
            'current' => [],
            'added' => [],
            'removed' => [],
            'errors' => [],
        ];

        if (! $enabled) {
            return $result;
        }

        try {
            $show = Process::run(['sudo', '-n', 'ip', 'route', 'show', 'proto', $proto]);
        } catch (\Throwable $e) {
            $result['errors'][] = 'Could not run the "ip" binary: '.$e->getMessage();

            return $result;
        }

        if (! $show->successful()) {
            $result['errors'][] = 'Could not list routes with proto "'.$proto.'": '.trim($show->errorOutput() ?: $show->output());

            return $result;
        }

        $result['binary_available'] = true;
        $result['current'] = $this->parseRouteList($show->output());

        $toAdd = array_values(array_diff($result['desired'], $result['current']));
        $toRemove = array_values(array_diff($result['current'], $result['desired']));

        foreach ($toAdd as $subnet) {
            if ($dryRun) {
                $result['added'][] = $subnet;

                continue;
            }

            if ($this->runRouteAction('add', $subnet, $result)) {
                $result['added'][] = $subnet;
            }
        }

        foreach ($toRemove as $subnet) {
            if ($dryRun) {
                $result['removed'][] = $subnet;

                continue;
            }

            if ($this->runRouteAction('del', $subnet, $result)) {
                $result['removed'][] = $subnet;
            }
        }

        return $result;
    }

    /**
     * @return list<string> deduplicated subnet CIDRs, across every router with
     *     route_lan_through_tunnel enabled
     */
    public function desiredRoutes(): array
    {
        $routes = Router::query()
            ->get(['provisioning_settings'])
            ->filter(fn (Router $router): bool => (bool) data_get($router->provisioning_settings, 'route_lan_through_tunnel'))
            ->flatMap(function (Router $router): array {
                return collect(['mgmt_network', 'staff_network'])
                    ->map(fn (string $key) => (string) data_get($router->provisioning_settings, $key, ''))
                    ->filter(fn (string $network): bool => $network !== '')
                    ->all();
            })
            ->unique()
            ->values()
            ->all();

        sort($routes);

        return $routes;
    }

    /**
     * @param  array{errors: array<int,string>}  $result
     */
    private function runRouteAction(string $action, string $subnet, array &$result): bool
    {
        try {
            $set = Process::input($action."\n".$subnet."\n")
                ->run(['sudo', '-n', (string) config('services.wireguard.set_route_script')]);
        } catch (\Throwable $e) {
            $result['errors'][] = "Failed to {$action} route {$subnet}: ".$e->getMessage();

            return false;
        }

        if (! $set->successful()) {
            $result['errors'][] = "Failed to {$action} route {$subnet}: ".trim($set->errorOutput() ?: $set->output());

            return false;
        }

        return true;
    }

    /**
     * Parses `ip route show proto <name>` output -- one route per line, each
     * starting with the destination CIDR (e.g. "192.168.10.0/24 dev wg-saas
     * proto hotspotfreerad scope link").
     *
     * @return list<string>
     */
    private function parseRouteList(string $output): array
    {
        $routes = collect(explode("\n", trim($output)))
            ->map(fn (string $line): string => trim(explode(' ', trim($line), 2)[0] ?? ''))
            ->filter(fn (string $subnet): bool => $subnet !== '')
            ->unique()
            ->values()
            ->all();

        sort($routes);

        return $routes;
    }
}
