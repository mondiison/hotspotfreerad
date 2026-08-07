<?php

namespace Tests\Unit;

use App\Jobs\SyncRouterWalledGarden;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncRouterWalledGardenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_a_no_op_when_the_router_no_longer_exists(): void
    {
        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldNotReceive('syncWalledGarden');

        (new SyncRouterWalledGarden(999999))->handle($routerOs);

        $this->assertTrue(true);
    }

    public function test_it_syncs_the_walled_garden_when_the_router_has_api_credentials(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Job Router',
            'nas_identifier' => 'job-router',
            'wireguard_internal_ip' => '10.8.0.210',
            'shared_secret' => 'radius-secret',
        ]);

        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldReceive('isConfigured')->once()->andReturn(true);
        $routerOs->shouldReceive('syncWalledGarden')->once()->withArgs(
            fn (Router $argRouter): bool => $argRouter->id === $router->id
        )->andReturn(['success' => true, 'steps' => []]);

        (new SyncRouterWalledGarden($router->id))->handle($routerOs);
    }
}
