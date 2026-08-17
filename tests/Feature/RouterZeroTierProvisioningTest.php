<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoutersIndex;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterManagementService;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouterZeroTierProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_zerotier_node_id_is_required_when_tunnel_mode_needs_it(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'ZeroTier Router')
            ->set('nas_identifier', 'zerotier-router')
            ->set('wireguard_internal_ip', '10.8.0.90')
            ->set('shared_secret', 'radius-secret')
            ->set('tunnel_mode', 'zerotier')
            ->call('nextStep')
            ->assertHasErrors(['zerotier_node_id']);
    }

    public function test_wireguard_only_mode_does_not_require_a_zerotier_node_id(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'WireGuard Router')
            ->set('nas_identifier', 'wireguard-router')
            ->set('wireguard_internal_ip', '10.8.0.91')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->assertHasNoErrors(['zerotier_node_id']);
    }

    public function test_super_admin_can_create_a_dual_tunnel_router(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Dual Tunnel Router')
            ->set('nas_identifier', 'dual-tunnel-router')
            ->set('wireguard_internal_ip', '10.8.0.92')
            ->set('shared_secret', 'radius-secret')
            ->set('tunnel_mode', 'wireguard_zerotier')
            ->set('zerotier_node_id', 'aaaa111111')
            ->set('zerotier_ip', '10.9.0.92')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->set('provisioning_settings.port_count', 8)
            ->set('provisioning_settings.wan1_port_number', 1)
            ->set('provisioning_settings.trunk_port_number', 2)
            ->set('provisioning_settings.pi_port_number', 3)
            ->call('nextStep')
            ->assertHasNoErrors()
            ->call('nextStep')
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors();

        $router = Router::where('nas_identifier', 'dual-tunnel-router')->firstOrFail();

        $this->assertSame('wireguard_zerotier', $router->tunnel_mode);
        $this->assertSame('aaaa111111', $router->zerotier_node_id);
        $this->assertSame('10.9.0.92', $router->zerotier_ip);
    }

    public function test_suggested_zerotier_ip_scans_the_configured_prefix(): void
    {
        config(['services.zerotier.ip_prefix' => '10.9.0']);

        $shop = $this->shop();

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Existing ZT Router',
            'nas_identifier' => 'existing-zt-router',
            'wireguard_internal_ip' => '10.8.0.93',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'zerotier',
            'zerotier_node_id' => 'bbbb222222',
            'zerotier_ip' => '10.9.0.15',
        ]);

        $suggested = app(RouterManagementService::class)->suggestedZeroTierIp();

        $this->assertSame('10.9.0.16', $suggested);
    }

    public function test_fetch_zerotier_node_id_surfaces_a_clear_error_for_an_unreachable_router(): void
    {
        $shop = $this->shop();

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Unreachable Dual Router',
            'nas_identifier' => 'unreachable-dual-router',
            // Reserved documentation-only address, guaranteed unreachable.
            'wireguard_internal_ip' => '192.0.2.20',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'wireguard_zerotier',
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->call('fetchZeroTierNodeId')
            ->assertHasErrors(['zerotier_node_id']);
    }

    public function test_fetch_zerotier_node_id_parses_the_id_from_the_identity_field(): void
    {
        // Real "/zerotier print detail" output, confirmed live (2026-08-19):
        // there is no plain "address" field -- the node ID is the part
        // before the first ":" in "identity"/"identity.public".
        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('runReadOnlyCommand')
                ->with(\Mockery::type(Router::class), '/zerotier print')
                ->andReturn([
                    'success' => true,
                    'rows' => [[
                        'name' => 'zt1',
                        'disabled' => 'no',
                        'port' => '9993',
                        'identity' => '69ae7250fc:0:b23acdb3c1c5dd6:47e598908c7cd60',
                        'identity.public' => '69ae7250fc:0:b23acdb3c1c5dd6',
                        'state' => 'running',
                    ]],
                ]);
        });

        $shop = $this->shop();

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Reachable Dual Router',
            'nas_identifier' => 'reachable-dual-router',
            'wireguard_internal_ip' => '10.8.0.94',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'wireguard_zerotier',
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->call('fetchZeroTierNodeId')
            ->assertHasNoErrors(['zerotier_node_id'])
            ->assertSet('zerotier_node_id', '69ae7250fc');
    }

    public function test_fetch_zerotier_node_id_errors_when_the_router_has_no_identity_yet(): void
    {
        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('runReadOnlyCommand')
                ->with(\Mockery::type(Router::class), '/zerotier print')
                ->andReturn([
                    'success' => true,
                    'rows' => [['name' => 'zt1', 'disabled' => 'no', 'port' => '9993']],
                ]);
        });

        $shop = $this->shop();

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'No Identity Router',
            'nas_identifier' => 'no-identity-router',
            'wireguard_internal_ip' => '10.8.0.95',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'wireguard_zerotier',
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->call('fetchZeroTierNodeId')
            ->assertHasErrors(['zerotier_node_id']);
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
