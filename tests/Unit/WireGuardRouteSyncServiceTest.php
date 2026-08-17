<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\WireGuardRouteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WireGuardRouteSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_is_a_no_op_when_route_management_is_disabled(): void
    {
        config(['services.wireguard.manage_routes' => false]);

        $result = app(WireGuardRouteSyncService::class)->reconcile();

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['binary_available']);
        $this->assertSame([], $result['added']);
        $this->assertSame([], $result['removed']);
        $this->assertSame([], $result['errors']);
    }

    public function test_reconcile_reports_an_error_instead_of_crashing_when_ip_binary_is_unavailable(): void
    {
        config(['services.wireguard.manage_routes' => true]);

        $result = app(WireGuardRouteSyncService::class)->reconcile();

        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['binary_available']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_desired_routes_includes_only_routers_with_lan_routing_enabled(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Opted In Router',
            'nas_identifier' => 'opted-in-router',
            'wireguard_internal_ip' => '10.8.0.80',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'route_lan_through_tunnel' => true,
                'mgmt_network' => '192.168.13.0/24',
                'staff_network' => '192.168.33.0/24',
            ],
        ]);

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Opted Out Router',
            'nas_identifier' => 'opted-out-router',
            'wireguard_internal_ip' => '10.8.0.81',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'route_lan_through_tunnel' => false,
                'mgmt_network' => '192.168.14.0/24',
            ],
        ]);

        $desired = app(WireGuardRouteSyncService::class)->desiredRoutes();

        $this->assertSame(['192.168.13.0/24', '192.168.33.0/24'], $desired);
    }

    public function test_desired_routes_deduplicates_identical_subnets(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        foreach (['router-a', 'router-b'] as $index => $identifier) {
            Router::create([
                'shop_id' => $shop->id,
                'name' => 'Router '.$identifier,
                'nas_identifier' => $identifier,
                'wireguard_internal_ip' => '10.8.0.'.(85 + $index),
                'shared_secret' => 'radius-secret',
                'provisioning_settings' => [
                    'route_lan_through_tunnel' => true,
                    'mgmt_network' => '192.168.15.0/24',
                ],
            ]);
        }

        $desired = app(WireGuardRouteSyncService::class)->desiredRoutes();

        $this->assertSame(['192.168.15.0/24'], $desired);
    }
}
