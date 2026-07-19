<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
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

    public function test_super_admin_layout_shows_platform_mode(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Platform Admin')
            ->assertDontSee('Tenant Admin')
            ->assertDontSee('Tenant Workspace')
            ->assertDontSee('Launch Checklist')
            ->assertDontSee('Public Page');
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
            ->assertSee('Tenant Admin')
            ->assertSee('Tenant Workspace')
            ->assertSee('Own Tenant')
            ->assertSee('/'.$ownTenant->slug)
            ->assertSee('Public Page')
            ->assertSee('Payment Setup')
            ->assertSee('Launch Checklist')
            ->assertSee('Customize tenant brand')
            ->assertSee('Connect payment account')
            ->assertSee(route('admin.payment-settings.index'), false)
            ->assertSee(route('admin.brand.edit'), false)
            ->assertSee(route('tenant.public-site', $ownTenant), false)
            ->assertSee('Own Router')
            ->assertDontSee('Other Router');
    }

    public function test_tenant_admin_dashboard_shows_billing_plan_usage(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Billing Tenant',
            'owner_email' => 'billing@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Billing Shop',
        ]);
        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Billing Router',
            'nas_identifier' => 'billing-router',
            'wireguard_internal_ip' => '10.8.0.40',
            'shared_secret' => 'radius-secret',
        ]);
        Package::create([
            'shop_id' => $shop->id,
            'name' => 'Weekly 10GB',
            'price' => 2500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 604800,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);
        $plan = BillingPlan::create([
            'name' => 'Starter',
            'slug' => 'starter-test',
            'monthly_price' => 15000,
            'currency' => 'NGN',
            'shop_limit' => 2,
            'router_limit' => 3,
            'package_limit' => 4,
            'is_active' => true,
        ]);
        TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => 15000,
            'currency' => 'NGN',
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Platform Plan')
            ->assertSee('Starter')
            ->assertSee('NGN 15,000.00/month')
            ->assertSee('Active')
            ->assertSee('Renews')
            ->assertSee('1 / 2')
            ->assertSee('1 / 3')
            ->assertSee('1 / 4');
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
