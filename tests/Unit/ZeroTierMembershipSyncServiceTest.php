<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\ZeroTierMembershipSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZeroTierMembershipSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenPath = storage_path('framework/testing/zerotier-membership-authtoken.secret');
        File::ensureDirectoryExists(dirname($this->tokenPath));
        File::put($this->tokenPath, "test-auth-token\n");

        config([
            'services.zerotier.controller_url' => 'http://127.0.0.1:9993',
            'services.zerotier.auth_token_path' => $this->tokenPath,
            'services.zerotier.network_id' => 'abcd1234abcd1234',
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->tokenPath);

        parent::tearDown();
    }

    private ?Shop $shop = null;

    private function makeRouter(string $tunnelMode, ?string $nodeId, ?string $ip): Router
    {
        if (! $this->shop) {
            $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
            $this->shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        }

        $shop = $this->shop;

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'ZT Router',
            'nas_identifier' => 'zt-router-'.uniqid(),
            'wireguard_internal_ip' => '10.8.0.'.random_int(20, 250),
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => $tunnelMode,
            'zerotier_node_id' => $nodeId,
            'zerotier_ip' => $ip,
        ]);
    }

    public function test_reconcile_is_a_no_op_when_member_management_is_disabled(): void
    {
        config(['services.zerotier.manage_members' => false]);

        $result = app(ZeroTierMembershipSyncService::class)->reconcile();

        $this->assertFalse($result['enabled']);
        $this->assertSame([], $result['authorized']);
        $this->assertSame([], $result['errors']);
    }

    public function test_desired_nodes_only_includes_routers_with_a_zerotier_capable_tunnel_mode_and_node_id(): void
    {
        $this->makeRouter('wireguard', null, null);
        $ztOnly = $this->makeRouter('zerotier', 'zzzz111111', '10.9.0.20');
        $dual = $this->makeRouter('wireguard_zerotier', 'zzzz222222', '10.9.0.21');
        $this->makeRouter('wireguard_zerotier', null, null); // node ID not known yet

        $desired = app(ZeroTierMembershipSyncService::class)->desiredNodes();

        $this->assertSame([
            'zzzz111111' => '10.9.0.20',
            'zzzz222222' => '10.9.0.21',
        ], $desired);
        $this->assertNotNull($ztOnly);
        $this->assertNotNull($dual);
    }

    public function test_reconcile_authorizes_a_known_router_and_records_the_timestamp(): void
    {
        config(['services.zerotier.manage_members' => true]);

        $router = $this->makeRouter('zerotier', 'zzzz333333', '10.9.0.22');

        Http::fake([
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member' => Http::response([]),
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member/*' => Http::response(['nodeId' => 'zzzz333333']),
        ]);

        $result = app(ZeroTierMembershipSyncService::class)->reconcile();

        $this->assertTrue($result['enabled']);
        $this->assertSame([], $result['errors']);
        $this->assertArrayHasKey('zzzz333333', $result['authorized']);

        $router->refresh();
        $this->assertNotNull($router->zerotier_authorized_at);
    }

    public function test_reconcile_reports_a_controller_node_it_cannot_attribute_to_any_router(): void
    {
        config(['services.zerotier.manage_members' => true]);

        Http::fake([
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member' => Http::response([
                ['nodeId' => 'unknown999999', 'config' => ['authorized' => false, 'ipAssignments' => []]],
            ]),
        ]);

        $result = app(ZeroTierMembershipSyncService::class)->reconcile();

        $this->assertContains('unknown999999', $result['unmatched_members']);
    }
}
