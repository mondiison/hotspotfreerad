<?php

namespace Tests\Unit;

use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\RadiusProvisioningService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RadiusProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRadiusTables();
    }

    public function test_it_syncs_a_router_to_the_nas_table(): void
    {
        $shop = $this->shop($this->tenant());

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'shop-router-1',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        app(RadiusProvisioningService::class)->syncRouter($router);

        $this->assertDatabaseHas('nas', [
            'nasname' => '10.8.0.10',
            'shortname' => 'shop-router-1',
            'type' => 'mikrotik',
            'secret' => 'radius-secret',
            'description' => 'Main Router (services: hotspot, ppp)',
        ]);
    }

    public function test_it_syncs_package_radius_group_reply_rows(): void
    {
        $package = $this->package();

        $groupName = app(RadiusProvisioningService::class)->syncPackageProfile($package);

        $this->assertSame("tenant_{$package->shop->tenant_id}_shop_{$package->shop_id}_one_hour_ultra", $groupName);
        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'radius_group_name' => $groupName,
        ]);
        $this->assertDatabaseHas('radgroupreply', [
            'groupname' => $groupName,
            'attribute' => 'Mikrotik-Rate-Limit',
            'op' => ':=',
            'value' => '5M/5M',
        ]);
        $this->assertDatabaseHas('radgroupreply', [
            'groupname' => $groupName,
            'attribute' => 'Session-Timeout',
            'op' => ':=',
            'value' => '3600',
        ]);
    }

    public function test_it_syncs_total_transfer_limit_for_limited_data_packages(): void
    {
        $package = $this->package();
        $package->update(['data_limit_bytes' => 5368709120]);

        $groupName = app(RadiusProvisioningService::class)->syncPackageProfile($package);

        $this->assertDatabaseHas('radgroupreply', [
            'groupname' => $groupName,
            'attribute' => 'Mikrotik-Total-Limit',
            'op' => ':=',
            'value' => '1073741824',
        ]);
        $this->assertDatabaseHas('radgroupreply', [
            'groupname' => $groupName,
            'attribute' => 'Mikrotik-Total-Limit-Gigawords',
            'op' => ':=',
            'value' => '1',
        ]);
    }

    public function test_it_grants_and_revokes_mac_access_through_radius_tables(): void
    {
        $package = $this->package();
        $subscription = Subscription::create([
            'shop_id' => $package->shop_id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'starts_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $service = app(RadiusProvisioningService::class);

        $service->grantSubscriptionAccess($subscription);
        $package->refresh();

        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
        ]);
        $this->assertDatabaseHas('radusergroup', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'groupname' => $package->radius_group_name,
            'priority' => 1,
        ]);

        $service->revokeMacAccess('AA:BB:CC:DD:EE:FF');

        $this->assertDatabaseMissing('radcheck', ['username' => 'AA:BB:CC:DD:EE:FF']);
        $this->assertDatabaseMissing('radusergroup', ['username' => 'AA:BB:CC:DD:EE:FF']);
    }

    private function createRadiusTables(): void
    {
        if (Schema::hasTable('nas')) {
            return;
        }

        Schema::create('nas', function (Blueprint $table) {
            $table->id();
            $table->string('nasname')->unique();
            $table->string('shortname')->nullable();
            $table->string('type')->nullable();
            $table->integer('ports')->nullable();
            $table->string('secret')->nullable();
            $table->string('server')->nullable();
            $table->string('community')->nullable();
            $table->string('description')->nullable();
        });

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

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);
    }

    private function package(): Package
    {
        $shop = $this->shop($this->tenant());

        return Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
        ]);
    }

    private function shop(Tenant $tenant): Shop
    {
        return Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
            'location_city' => 'Lagos',
        ]);
    }
}
