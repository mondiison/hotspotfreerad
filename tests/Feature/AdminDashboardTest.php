<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_operational_overview(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Hall',
        ]);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
            'is_online' => true,
            'last_seen_at' => now(),
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        Subscription::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'starts_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        DB::table('radacct')->insert([
            'acctsessionid' => 'session-1',
            'acctuniqueid' => 'unique-session-1',
            'username' => 'AA:BB:CC:DD:EE:FF',
            'nasipaddress' => '10.8.0.10',
            'acctstarttime' => now()->subMinutes(5),
            'acctupdatetime' => now(),
            'acctstoptime' => null,
            'acctinputoctets' => 1048576,
            'acctoutputoctets' => 2097152,
            'callingstationid' => 'AA:BB:CC:DD:EE:FF',
            'framedipaddress' => '192.168.88.20',
        ]);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Router Health')
            ->assertSee($router->name)
            ->assertSee('Online')
            ->assertSee('Users Online')
            ->assertSee('3.0 MB')
            ->assertSee('Recent Access Grants')
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('Daily 5GB')
            ->assertSee('Setup Progress');
    }

    public function test_tenant_admin_dashboard_is_scoped_to_their_tenant(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Own Tenant',
            'owner_email' => 'own@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $ownShop = Shop::create([
            'tenant_id' => $ownTenant->id,
            'name' => 'Own Shop',
        ]);
        $otherShop = Shop::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Shop',
        ]);

        Router::create([
            'shop_id' => $ownShop->id,
            'name' => 'Own Router',
            'nas_identifier' => 'own-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);
        Router::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Router',
            'nas_identifier' => 'other-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Own Router')
            ->assertDontSee('Other Router');
    }

    public function test_dashboard_does_not_mark_quiet_router_online_without_accounting(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Quiet Tenant',
            'owner_email' => 'quiet@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Quiet Shop',
        ]);

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Quiet Router',
            'nas_identifier' => 'quiet-router',
            'wireguard_internal_ip' => '10.8.0.30',
            'shared_secret' => 'radius-secret',
            'is_online' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Quiet Router')
            ->assertSee('No accounting yet')
            ->assertSee('No users are online right now.');
    }
}
