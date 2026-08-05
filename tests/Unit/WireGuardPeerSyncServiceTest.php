<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\WireGuardPeerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WireGuardPeerSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_is_a_no_op_when_peer_management_is_disabled(): void
    {
        config(['services.wireguard.manage_peers' => false]);

        $result = app(WireGuardPeerSyncService::class)->reconcile();

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['binary_available']);
        $this->assertSame([], $result['added']);
        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['errors']);
    }

    public function test_reconcile_reports_an_error_instead_of_crashing_when_wg_binary_is_unavailable(): void
    {
        config(['services.wireguard.manage_peers' => true]);

        $result = app(WireGuardPeerSyncService::class)->reconcile();

        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['binary_available']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_desired_peers_maps_public_key_to_internal_ip_for_routers_with_a_key(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Peer Router',
            'nas_identifier' => 'peer-router',
            'wireguard_internal_ip' => '10.8.0.70',
            'shared_secret' => 'radius-secret',
        ]);

        $desired = app(WireGuardPeerSyncService::class)->desiredPeers();

        $this->assertArrayHasKey($router->wireguard_public_key, $desired);
        $this->assertSame($router->wireguard_internal_ip, $desired[$router->wireguard_public_key]);
    }
}
