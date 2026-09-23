<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\RouterOsConnectionService;
use App\Services\RouterAutoProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterAutoProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private ?Shop $shop = null;

    private function makeRouter(array $overrides = []): Router
    {
        if (! $this->shop) {
            $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
            $this->shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        }

        return Router::create(array_merge([
            'shop_id' => $this->shop->id,
            'name' => 'Auto Provision Router',
            'nas_identifier' => 'auto-provision-router-'.uniqid(),
            'wireguard_internal_ip' => '10.8.0.'.random_int(20, 250),
            'shared_secret' => 'radius-secret',
        ], $overrides));
    }

    public function test_reconcile_is_a_no_op_when_disabled(): void
    {
        config(['services.mikrotik.auto_provision_routers' => false]);

        $result = app(RouterAutoProvisioningService::class)->reconcile();

        $this->assertFalse($result['enabled']);
        $this->assertSame([], $result['provisioned']);
        $this->assertSame([], $result['pending']);
        $this->assertSame([], $result['errors']);
    }

    public function test_a_router_without_api_credentials_is_skipped(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);

        // api_username/api_password are auto-generated on create() by the model's
        // booted() hook (which only fires on insert, not update), so this scenario
        // can't actually occur for a real router -- this test documents the query's
        // whereNotNull('api_username') guard exists, via a manual clear afterward.
        $router = $this->makeRouter();
        $router->forceFill(['api_username' => null])->save();

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldNotReceive('provisionHotspot');
            $mock->shouldNotReceive('provisionPppoe');
            $mock->shouldNotReceive('provisionPos');
        });

        app(RouterAutoProvisioningService::class)->reconcile();
    }

    public function test_a_router_already_auto_provisioned_is_skipped(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);
        $this->makeRouter(['auto_provisioned_at' => now()]);

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldNotReceive('provisionHotspot');
        });

        $result = app(RouterAutoProvisioningService::class)->reconcile();

        $this->assertSame([], $result['provisioned']);
        $this->assertSame([], $result['pending']);
    }

    public function test_an_unreachable_router_stays_pending_without_throwing(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);
        $router = $this->makeRouter();

        $this->mock(RouterOsConnectionService::class, function ($mock) use ($router): void {
            $mock->shouldReceive('provisionHotspot')->once()->with(\Mockery::on(fn ($r) => $r->is($router)))->andReturn([
                'success' => false,
                'steps' => [['label' => 'Add RADIUS client', 'success' => false, 'error' => 'Connection timed out']],
            ]);
            $mock->shouldReceive('provisionPos')->once()->andReturn(['success' => true, 'steps' => []]);
        });

        $result = app(RouterAutoProvisioningService::class)->reconcile();

        $this->assertSame([$router->name], $result['pending']);
        $this->assertSame([], $result['provisioned']);
        $this->assertNotEmpty($result['errors']);

        $router->refresh();
        $this->assertNull($router->auto_provisioned_at);
    }

    public function test_a_fully_successful_router_gets_marked_auto_provisioned(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);
        $router = $this->makeRouter();

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('provisionHotspot')->once()->andReturn(['success' => true, 'steps' => []]);
            $mock->shouldReceive('provisionPos')->once()->andReturn(['success' => true, 'steps' => []]);
        });

        $result = app(RouterAutoProvisioningService::class)->reconcile();

        $this->assertSame([$router->name], $result['provisioned']);
        $this->assertSame([], $result['pending']);

        $router->refresh();
        $this->assertNotNull($router->auto_provisioned_at);
    }

    public function test_pppoe_is_only_provisioned_when_enabled(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);
        $this->makeRouter(['provisioning_settings' => ['enable_pppoe' => true]]);

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('provisionHotspot')->once()->andReturn(['success' => true, 'steps' => []]);
            $mock->shouldReceive('provisionPppoe')->once()->andReturn(['success' => true, 'steps' => []]);
            $mock->shouldReceive('provisionPos')->once()->andReturn(['success' => true, 'steps' => []]);
        });

        app(RouterAutoProvisioningService::class)->reconcile();
    }

    public function test_pppoe_is_never_called_when_disabled(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);
        $this->makeRouter(['provisioning_settings' => ['enable_pppoe' => false]]);

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('provisionHotspot')->once()->andReturn(['success' => true, 'steps' => []]);
            $mock->shouldNotReceive('provisionPppoe');
            $mock->shouldReceive('provisionPos')->once()->andReturn(['success' => true, 'steps' => []]);
        });

        app(RouterAutoProvisioningService::class)->reconcile();
    }

    /**
     * Unlike PPPoE, provisionPos() is always called regardless of enable_pos --
     * it already no-ops internally for a POS-disabled router (see
     * RouterOsConnectionService::provisionPos()'s own default-true fallback),
     * so this service deliberately doesn't duplicate that check itself.
     */
    public function test_pos_is_always_provisioned_regardless_of_enable_pos(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);
        $this->makeRouter(['provisioning_settings' => ['enable_pos' => false]]);

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('provisionHotspot')->once()->andReturn(['success' => true, 'steps' => []]);
            $mock->shouldReceive('provisionPos')->once()->andReturn(['success' => true, 'steps' => []]);
        });

        app(RouterAutoProvisioningService::class)->reconcile();
    }

    public function test_dry_run_only_tests_connectivity_and_writes_nothing(): void
    {
        config(['services.mikrotik.auto_provision_routers' => true]);
        $router = $this->makeRouter();

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('testConnection')->once()->andReturn(['success' => true, 'identity' => 'test-router']);
            $mock->shouldNotReceive('provisionHotspot');
            $mock->shouldNotReceive('provisionPppoe');
            $mock->shouldNotReceive('provisionPos');
        });

        $result = app(RouterAutoProvisioningService::class)->reconcile(dryRun: true);

        $this->assertSame([$router->name], $result['provisioned']);

        $router->refresh();
        $this->assertNull($router->auto_provisioned_at);
    }
}
