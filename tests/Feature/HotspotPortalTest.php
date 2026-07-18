<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HotspotPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRadiusTables();
    }

    public function test_portal_resolves_router_and_lists_active_packages(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);

        $this->get('/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid=demo-router')
            ->assertOk()
            ->assertSee('Demo Shop')
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('One Hour Ultra')
            ->assertSee('NGN 500.00')
            ->assertSee('Start free access');
    }

    public function test_portal_displays_helpful_page_for_unknown_router(): void
    {
        $this->get('/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid=missing-router')
            ->assertOk()
            ->assertSee('Router not registered')
            ->assertSee('missing-router')
            ->assertSee('AA:BB:CC:DD:EE:FF');
    }

    public function test_portal_displays_helpful_page_when_mikrotik_parameters_are_missing(): void
    {
        $this->get('/hotspot/portal')
            ->assertOk()
            ->assertSee('Redirect incomplete')
            ->assertSee('Device MAC')
            ->assertSee('Missing');
    }

    public function test_grant_creates_subscription_and_radius_access(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $this->post('/hotspot/grant', [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'link-login' => 'http://hotspot.local/login',
            'link-orig' => 'http://neverssl.com',
        ])
            ->assertOk()
            ->assertSee('Access provisioned')
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('http://hotspot.local/login', false);

        $this->assertDatabaseHas('customers', [
            'shop_id' => $router->shop_id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => 'authenticated_device_pass',
        ]);
        $this->assertDatabaseHas('radusergroup', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'groupname' => 'tenant_1_shop_1_one_hour_ultra',
        ]);
    }

    private function routerWithPackage(): array
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);

        return [$router, $package];
    }

    private function createRadiusTables(): void
    {
        if (Schema::hasTable('radcheck')) {
            return;
        }

        Schema::create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });

        Schema::create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });

        Schema::create('radusergroup', function (Blueprint $table) {
            $table->string('username');
            $table->string('groupname');
            $table->integer('priority')->default(1);
        });

        Schema::create('radgroupreply', function (Blueprint $table) {
            $table->id();
            $table->string('groupname');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });
    }
}
