<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoutersIndex;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouterLanRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_lan_through_tunnel_toggle_is_visible_and_settable_in_step_4(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'LAN Routing Router')
            ->set('nas_identifier', 'lan-routing-router')
            ->set('wireguard_internal_ip', '10.8.0.95')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep');

        $component
            ->assertSee('Route this router\'s management/staff networks through the WireGuard tunnel')
            ->set('provisioning_settings.mgmt_network', '192.168.61.0/24')
            ->set('provisioning_settings.staff_network', '192.168.62.0/24')
            ->set('provisioning_settings.route_lan_through_tunnel', true)
            ->call('save')
            ->assertHasNoErrors();

        $router = Router::where('nas_identifier', 'lan-routing-router')->firstOrFail();
        $this->assertTrue($router->provisioning_settings['route_lan_through_tunnel']);
    }

    public function test_two_routers_with_overlapping_subnets_cannot_both_enable_lan_routing(): void
    {
        $shop = $this->shop();
        $admin = $this->superAdmin();

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'First LAN Routing Router',
            'nas_identifier' => 'first-lan-routing-router',
            'wireguard_internal_ip' => '10.8.0.96',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'route_lan_through_tunnel' => true,
                'mgmt_network' => '192.168.63.0/24',
                'staff_network' => '192.168.64.0/24',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Second LAN Routing Router')
            ->set('nas_identifier', 'second-lan-routing-router')
            ->set('wireguard_internal_ip', '10.8.0.97')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->set('provisioning_settings.mgmt_network', '192.168.63.0/24')
            ->set('provisioning_settings.route_lan_through_tunnel', true)
            ->call('save')
            ->assertHasErrors(['provisioning_settings.route_lan_through_tunnel']);
    }

    public function test_two_routers_with_overlapping_subnets_are_fine_when_only_one_opts_in(): void
    {
        $shop = $this->shop();

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Default Subnet Router',
            'nas_identifier' => 'default-subnet-router',
            'wireguard_internal_ip' => '10.8.0.98',
            'shared_secret' => 'radius-secret',
            // Left on the default mgmt_network (192.168.10.0/24), route_lan_through_tunnel off.
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Opted In Router')
            ->set('nas_identifier', 'opted-in-router-2')
            ->set('wireguard_internal_ip', '10.8.0.99')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            // Same default mgmt_network (192.168.10.0/24) as the router above -- fine,
            // since that router never enabled route_lan_through_tunnel.
            ->set('provisioning_settings.route_lan_through_tunnel', true)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_lan_routing_toggle_does_not_block_overlapping_subnets_when_left_off(): void
    {
        $shop = $this->shop();

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'First Default Router',
            'nas_identifier' => 'first-default-router',
            'wireguard_internal_ip' => '10.8.0.100',
            'shared_secret' => 'radius-secret',
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Second Default Router')
            ->set('nas_identifier', 'second-default-router')
            ->set('wireguard_internal_ip', '10.8.0.101')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('save')
            ->assertHasNoErrors();
    }

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

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
