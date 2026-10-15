<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\MikroTikProvisioningService;
use App\Support\RouterPortLayout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 2026-10-15, direct request for a UniFi-style visual port/VLAN diagram --
 * follows RouterAvailability's exact self-contained-island shape (locked
 * routerId, loads its own data in mount(), zero RouterController changes).
 * Read-only: this shows a router's already-saved port/VLAN configuration,
 * it never edits it -- that's still the wizard's own drag-and-drop port
 * mapper (Phase 2 of the router-wizard work).
 *
 * Deliberately not a new, independently-guessed port→role model: the exact
 * same RouterPortLayout::bucketAssignments() the wizard's own drag-and-drop
 * mapper uses, and the exact same MikroTikProvisioningService::
 * provisioningSettings() the script generator reads VLAN IDs/defaults from
 * -- reusing both is what keeps this tab from ever silently disagreeing
 * with what "Push via API"/the generated scripts actually configure.
 */
class RouterPortMap extends Component
{
    #[Locked]
    public int $routerId;

    /** @var list<array{number: int, interface: string, role: string, role_label: string, role_color: string, vlan_label: string, detail: string, assigned: bool}> */
    public array $ports = [];

    /** @var list<array{label: string, color: string}> */
    public array $legend = [];

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
        $this->loadPorts();
    }

    public function loadPorts(): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $profile = (string) (((array) $router->provisioning_settings)['profile'] ?? 'starlink_plaza');
        $settings = app(MikroTikProvisioningService::class)->provisioningSettings($router, $profile);
        $buckets = RouterPortLayout::bucketAssignments($settings);

        $roles = $this->roleDefinitions($settings);

        $portCount = (int) ($settings['port_count'] ?? 8);
        $highestAssigned = collect($buckets)->flatten()->max() ?? 0;
        $portCount = max($portCount, (int) $highestAssigned, 1);

        // A port can only ever belong to one role in practice (that's what
        // the wizard's own conflict check -- including the advanced-mode
        // version added alongside this feature -- now enforces at save
        // time), but this read-only view makes no assumption either way:
        // the first bucket a port appears in wins, so a router that somehow
        // still has a conflict (saved before that check existed) renders
        // *something* rather than erroring.
        $portToRole = [];

        foreach ($buckets as $bucket => $numbers) {
            foreach ($numbers as $number) {
                $portToRole[$number] ??= $bucket;
            }
        }

        $this->ports = collect(range(1, $portCount))
            ->map(function (int $number) use ($portToRole, $roles): array {
                $bucket = $portToRole[$number] ?? null;
                $role = $roles[$bucket] ?? $roles['unassigned'];

                return [
                    'number' => $number,
                    'interface' => RouterPortLayout::interfaceName($number),
                    'role' => $bucket ?? 'unassigned',
                    'role_label' => $role['label'],
                    'role_color' => $role['color'],
                    'vlan_label' => $role['vlan_label'],
                    'detail' => $role['detail'],
                    'assigned' => $bucket !== null,
                ];
            })
            ->all();

        $this->legend = collect($roles)
            ->reject(fn (array $role, string $key): bool => $key === 'unassigned')
            ->map(fn (array $role): array => ['label' => $role['label'], 'color' => $role['color']])
            ->unique('label')
            ->values()
            ->all();
    }

    /**
     * One entry per bucket key from RouterPortLayout::bucketAssignments(),
     * plus "unassigned" -- label/color/VLAN/detail text for each. Colors
     * reuse the two already established elsewhere in this app
     * (trusted-wifi-devices-index.blade.php: mgmt=purple, staff=blue) and
     * extend with distinct colors for the rest, so a role always reads the
     * same color everywhere it appears in the admin panel.
     *
     * @return array<string, array{label: string, color: string, vlan_label: string, detail: string}>
     */
    private function roleDefinitions(array $settings): array
    {
        return [
            'wan1' => [
                'label' => 'WAN 1',
                'color' => 'orange',
                'vlan_label' => 'Internet uplink',
                'detail' => 'Primary internet uplink (e.g. Starlink/ISP router). Gets a DHCP-assigned IP and default route.',
            ],
            'wan2' => [
                'label' => 'WAN 2',
                'color' => 'orange',
                'vlan_label' => 'Internet uplink',
                'detail' => 'Second internet uplink, load-balanced with WAN 1 via equal-distance ECMP routes.',
            ],
            'trunk' => [
                'label' => 'AP/switch trunk',
                'color' => 'indigo',
                'vlan_label' => 'All VLANs tagged',
                'detail' => 'Carries every enabled VLAN tagged, untagged frames dropped -- connects to an external AP or managed switch, not a single end device.',
            ],
            'pi' => [
                'label' => 'Pi/management',
                'color' => 'purple',
                'vlan_label' => 'VLAN '.($settings['mgmt_vlan'] ?? 10).' (untagged)',
                'detail' => 'Untagged management VLAN access for the Raspberry Pi and direct admin access.',
            ],
            'extra_mgmt' => [
                'label' => 'Extra management',
                'color' => 'purple',
                'vlan_label' => 'VLAN '.($settings['mgmt_vlan'] ?? 10).' (untagged)',
                'detail' => 'Additional untagged management-VLAN access port.',
            ],
            'extra_hotspot' => [
                'label' => 'Hotspot',
                'color' => 'green',
                'vlan_label' => 'VLAN '.($settings['hotspot_vlan'] ?? 20).' (untagged)',
                'detail' => 'Untagged customer hotspot VLAN access -- for a non-VLAN-capable AP or direct testing.',
            ],
            'extra_staff' => [
                'label' => 'Staff',
                'color' => 'blue',
                'vlan_label' => 'VLAN '.($settings['staff_vlan'] ?? 30).' (untagged)',
                'detail' => 'Untagged staff/admin Wi-Fi VLAN access port.',
            ],
            'extra_pos' => [
                'label' => 'POS',
                'color' => 'pink',
                'vlan_label' => 'VLAN '.($settings['pos_vlan'] ?? 50).' (untagged)',
                'detail' => 'Untagged POS VLAN access -- registered devices only, MAC-auth enforced.',
            ],
            'extra_pppoe' => [
                'label' => 'PPPoE',
                'color' => 'cyan',
                'vlan_label' => 'VLAN '.($settings['pppoe_vlan'] ?? 40).' (untagged)',
                'detail' => 'Untagged PPPoE/CPE VLAN access for a wired subscriber.',
            ],
            'unassigned' => [
                'label' => 'Unassigned',
                'color' => 'zinc',
                'vlan_label' => 'Not configured',
                'detail' => 'Not assigned to any role in this router\'s saved settings.',
            ],
        ];
    }

    public function render()
    {
        return view('livewire.admin.router-port-map');
    }
}