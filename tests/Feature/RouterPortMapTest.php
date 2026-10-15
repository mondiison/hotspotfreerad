<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterPortMap;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-10-15, direct request for a UniFi-style visual port/VLAN diagram on
 * a router's own show page -- read-only, reuses the exact same
 * RouterPortLayout::bucketAssignments()/MikroTikProvisioningService::
 * provisioningSettings() data the wizard's port mapper and script
 * generator already use, so this view can never silently disagree with
 * what "Push via API"/the generated scripts actually configure.
 */
class RouterPortMapTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create([
            'tenant_id' => Tenant::create([
                'company_name' => 'Demo Tenant',
                'owner_email' => fake()->unique()->safeEmail(),
            ])->id,
            'name' => 'Demo Shop',
            'is_active' => true,
        ]);
    }

    public function test_configured_and_unassigned_ports_get_the_right_role_and_vlan_labels(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Port Map Router',
            'nas_identifier' => 'port-map-router',
            'wireguard_internal_ip' => '10.8.0.230',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'port_count' => 5,
                'wan1_port_number' => 1,
                'trunk_port_number' => 2,
                'pi_port_number' => 3,
                'extra_hotspot_port_numbers' => '5',
            ],
        ]);

        $component = Livewire::test(RouterPortMap::class, ['router' => $router]);

        $ports = collect($component->get('ports'))->keyBy('number');

        $this->assertSame('WAN 1', $ports[1]['role_label']);
        $this->assertSame('AP/switch trunk', $ports[2]['role_label']);
        $this->assertSame('All VLANs tagged', $ports[2]['vlan_label']);
        $this->assertSame('Pi/management', $ports[3]['role_label']);
        $this->assertSame('Unassigned', $ports[4]['role_label']);
        $this->assertFalse($ports[4]['assigned']);
        $this->assertSame('Hotspot', $ports[5]['role_label']);
        $this->assertStringContainsString('VLAN 20', $ports[5]['vlan_label']);
    }

    /**
     * Confirmed necessary live: this is exactly the kind of router that
     * motivated building this feature -- its settings are saved entirely in
     * advanced mode (raw interface-name strings, no *_port_number fields at
     * all), and it still needs a correct, readable port map.
     */
    public function test_advanced_mode_routers_still_render_a_correct_port_map(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Advanced Port Map Router',
            'nas_identifier' => 'advanced-port-map-router',
            'wireguard_internal_ip' => '10.8.0.231',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'ports_advanced_mode' => true,
                'trunk_port' => 'ether3',
                'pi_port' => 'ether2',
            ],
        ]);

        $component = Livewire::test(RouterPortMap::class, ['router' => $router]);

        $ports = collect($component->get('ports'))->keyBy('number');

        $this->assertSame('AP/switch trunk', $ports[3]['role_label']);
        $this->assertSame('Pi/management', $ports[2]['role_label']);
    }

    public function test_disabled_features_do_not_leave_stale_port_assignments(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Staff Disabled Router',
            'nas_identifier' => 'staff-disabled-router',
            'wireguard_internal_ip' => '10.8.0.232',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'port_count' => 4,
                'enable_staff' => false,
                'extra_staff_port_numbers' => '',
            ],
        ]);

        $component = Livewire::test(RouterPortMap::class, ['router' => $router]);

        $ports = collect($component->get('ports'));

        $this->assertTrue($ports->every(fn (array $port): bool => $port['role'] !== 'extra_staff'));
    }
}
