<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PppoeSubscriber;
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
            ->assertSee('wire:navigate', false)
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
            ->assertSee('2FA optional')
            ->assertSee('Two-factor authentication is optional for this tenant, but enabling it protects owner access.')
            ->assertSee('Set up 2FA')
            ->assertSee(route('admin.profile.edit'), false)
            ->assertSee('Public Page')
            ->assertSee('Payment Setup')
            ->assertSee('Launch Checklist')
            ->assertSee('Customize tenant brand')
            ->assertSee('Connect payment account')
            ->assertSee(route('admin.payment-settings.index'), false)
            ->assertSee(route('admin.brand.edit'), false)
            ->assertSee(route('admin.shops.index'), false)
            ->assertSee(route('admin.routers.index'), false)
            ->assertSee(route('tenant.public-site', $ownTenant), false)
            ->assertSee('Own Router')
            ->assertDontSee('Other Router');
    }

    public function test_dashboard_shows_pppoe_service_desk_summary(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'PPPoE Tenant',
            'owner_email' => 'pppoe@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fiber Estate',
        ]);
        $otherShop = Shop::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Estate',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Home 10M',
            'service_type' => 'pppoe',
            'price' => 12000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Home',
            'service_type' => 'pppoe',
            'price' => 12000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);
        PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'due-soon',
            'password' => 'secret-pass',
            'full_name' => 'Due Soon Customer',
            'expires_at' => now()->addDays(3),
            'last_provisioned_at' => now(),
            'is_active' => true,
        ]);
        PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'unsynced-user',
            'password' => 'secret-pass',
            'expires_at' => now()->addDays(20),
            'is_active' => true,
        ]);
        PppoeSubscriber::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'username' => 'other-user',
            'password' => 'secret-pass',
            'expires_at' => now()->addDays(3),
            'last_provisioned_at' => now(),
            'is_active' => true,
        ]);
        DB::table('radacct')->insert([
            'acctsessionid' => 'pppoe-session',
            'acctuniqueid' => 'pppoe-unique-session',
            'username' => 'due-soon',
            'nasipaddress' => '10.8.0.10',
            'acctstarttime' => now()->subMinutes(10),
            'acctupdatetime' => now(),
            'acctstoptime' => null,
            'acctinputoctets' => 1000,
            'acctoutputoctets' => 2000,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('PPPoE Service Desk')
            ->assertSee('2 fixed subscribers')
            ->assertSee('Due Soon Customer')
            ->assertSee('due-soon')
            ->assertSee('Fiber Estate')
            ->assertSee(route('admin.pppoe-subscribers.index', ['status' => 'expiring_soon']), false)
            ->assertSee(route('admin.pppoe-subscribers.index', ['status' => 'unsynced']), false)
            ->assertDontSee('other-user')
            ->assertDontSee('Other Estate');
    }

    public function test_tenant_admin_dashboard_shows_enforced_two_factor_status(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Secure Tenant',
            'owner_email' => 'secure@example.com',
            'require_two_factor' => true,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'secure@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-one'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Tenant Workspace')
            ->assertSee('Secure Tenant')
            ->assertSee('2FA enforced')
            ->assertSee('Tenant policy is active and your owner login has confirmed two-factor protection.')
            ->assertSee('Review security')
            ->assertSee(route('admin.profile.edit'), false);
    }

    public function test_tenant_launch_checklist_points_payment_to_shop_creation_before_shops_exist(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'New Tenant',
            'owner_email' => 'new@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Create a shop first, then add Flutterwave credentials.')
            ->assertSee('Add shop')
            ->assertSee(route('admin.shops.create'), false);
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

    public function test_dashboard_shows_commission_revenue_breakdown(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Commission Tenant',
            'owner_email' => 'commission@example.com',
            'billing_model' => 'commission',
            'commission_rate' => 10,
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Commission Shop',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily',
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
        Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'DASH-COMMISSION',
            'amount' => 1000,
            'gross_amount' => 1000,
            'platform_fee_amount' => 100,
            'tenant_net_amount' => 900,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
            'payload' => [],
        ]);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Gross Sales')
            ->assertSee('Platform Commission')
            ->assertSee('Tenant Net')
            ->assertSee('NGN 1,000.00')
            ->assertSee('NGN 100.00')
            ->assertSee('NGN 900.00');
    }

    public function test_dashboard_shows_current_month_finance_summary(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Hall',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'DASH-MONTH-CURRENT',
            'amount' => 2000,
            'gross_amount' => 2000,
            'platform_fee_amount' => 200,
            'tenant_net_amount' => 1800,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
            'payload' => [],
        ]);
        Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'DASH-MONTH-OLD',
            'amount' => 5000,
            'gross_amount' => 5000,
            'platform_fee_amount' => 500,
            'tenant_net_amount' => 4500,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now()->subMonth(),
            'payload' => [],
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Router maintenance',
            'amount' => 800,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Old rent',
            'amount' => 7000,
            'currency' => 'NGN',
            'incurred_on' => now()->subMonth()->toDateString(),
        ]);

        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $otherShop = Shop::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Hall',
        ]);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Daily',
            'price' => 9999,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        Payment::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'DASH-MONTH-OTHER',
            'amount' => 9999,
            'gross_amount' => 9999,
            'platform_fee_amount' => 999,
            'tenant_net_amount' => 9000,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
            'payload' => [],
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('This Month Finance')
            ->assertSee('NGN 2,000.00')
            ->assertSee('NGN 200.00')
            ->assertSee('NGN 1,800.00')
            ->assertSee('NGN 800.00')
            ->assertSee('NGN 1,000.00')
            ->assertSee('55.6%')
            ->assertSee('Top Packages')
            ->assertSee('Jul 2026 best sellers')
            ->assertSee('Daily')
            ->assertSee('Main Hall')
            ->assertSee('Payment report')
            ->assertSee('Top Locations')
            ->assertSee('Jul 2026 shop performance')
            ->assertSee('Active Access')
            ->assertSee('Manage shops')
            ->assertDontSee('Other Hall')
            ->assertDontSee('NGN 9,999.00');
    }

    public function test_dashboard_shows_payment_health_for_current_month(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Payment Tenant',
            'owner_email' => 'payments@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Payment Shop',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);

        foreach ([
            ['tx_ref' => 'DASH-HEALTH-SUCCESS', 'amount' => 1000, 'status' => 'successful'],
            ['tx_ref' => 'DASH-HEALTH-PENDING', 'amount' => 1500, 'status' => 'pending'],
            ['tx_ref' => 'DASH-HEALTH-FAILED', 'amount' => 2000, 'status' => 'failed'],
        ] as $paymentData) {
            Payment::create([
                'shop_id' => $shop->id,
                'package_id' => $package->id,
                'provider' => 'flutterwave',
                'tx_ref' => $paymentData['tx_ref'],
                'amount' => $paymentData['amount'],
                'gross_amount' => $paymentData['amount'],
                'platform_fee_amount' => 0,
                'tenant_net_amount' => $paymentData['status'] === 'successful' ? $paymentData['amount'] : 0,
                'currency' => 'NGN',
                'status' => $paymentData['status'],
                'paid_at' => $paymentData['status'] === 'successful' ? now() : null,
                'payload' => [],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $otherTenant = Tenant::create([
            'company_name' => 'Other Payment Tenant',
            'owner_email' => 'other-payments@example.com',
        ]);
        $otherShop = Shop::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Payment Shop',
        ]);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Daily',
            'price' => 9999,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        Payment::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'DASH-HEALTH-OTHER',
            'amount' => 9999,
            'gross_amount' => 9999,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 0,
            'currency' => 'NGN',
            'status' => 'pending',
            'payload' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Payment Health')
            ->assertSee('Jul 2026 checkout flow')
            ->assertSee('Attempts')
            ->assertSee('Success Rate')
            ->assertSee('33.3%')
            ->assertSee('Successful')
            ->assertSee('Pending')
            ->assertSee('Needs Attention')
            ->assertSee('NGN 1,000.00 confirmed')
            ->assertSee('NGN 1,500.00 awaiting callback/webhook')
            ->assertSee('NGN 3,500.00 pending or failed')
            ->assertSee(route('admin.payments.index', ['status' => 'pending']), false)
            ->assertSee(route('admin.payments.index', ['status' => 'failed']), false)
            ->assertSee(route('admin.payments.index', ['status' => 'attention']), false)
            ->assertDontSee('NGN 9,999.00');
    }

    public function test_dashboard_shows_recent_security_attention(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Security Tenant',
            'owner_email' => 'security@example.com',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $admin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'two_factor_challenge_failed',
            'label' => 'Two-factor challenge failed.',
            'ip_address' => '10.8.0.80',
            'created_at' => now(),
        ]);
        $admin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'password_updated',
            'label' => 'Password changed from dashboard test.',
            'ip_address' => '10.8.0.82',
            'created_at' => now(),
        ]);
        $admin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'login',
            'label' => 'Normal sign-in event.',
            'ip_address' => '10.8.0.81',
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Security Attention')
            ->assertSee('2 events in the last 30 days')
            ->assertSee('Two-factor challenge failed.')
            ->assertSee('Failed 2FA challenge')
            ->assertSee('Password changed')
            ->assertSee('Review activity')
            ->assertSee(route('admin.security-activity.index', ['attention' => '1']), false)
            ->assertSee('action=two_factor_challenge_failed', false)
            ->assertSee('action=password_updated', false)
            ->assertSee('attention=1', false)
            ->assertDontSee('Normal sign-in event.');
    }

    public function test_dashboard_security_attention_is_tenant_scoped(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Own Security Tenant',
            'owner_email' => 'own-security@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Security Tenant',
            'owner_email' => 'other-security@example.com',
        ]);
        $actor = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $ownAdmin = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $otherAdmin = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $ownAdmin->securityActivities()->create([
            'tenant_id' => $ownTenant->id,
            'action' => 'password_updated',
            'label' => 'Own tenant password changed.',
            'ip_address' => '10.8.0.82',
            'created_at' => now(),
        ]);
        $otherAdmin->securityActivities()->create([
            'tenant_id' => $otherTenant->id,
            'action' => 'two_factor_challenge_failed',
            'label' => 'Other tenant failed challenge.',
            'ip_address' => '10.8.0.83',
            'created_at' => now(),
        ]);

        $this->actingAs($actor)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Own tenant password changed.')
            ->assertDontSee('Other tenant failed challenge.');
    }

    public function test_dashboard_shows_six_month_finance_trend(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Trend Tenant',
            'owner_email' => 'trend@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Trend Shop',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily',
            'price' => 2000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'DASH-TREND-CURRENT',
            'amount' => 2000,
            'gross_amount' => 2000,
            'platform_fee_amount' => 200,
            'tenant_net_amount' => 1800,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
            'payload' => [],
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Trend repair',
            'amount' => 800,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
        ]);

        $otherTenant = Tenant::create([
            'company_name' => 'Other Trend Tenant',
            'owner_email' => 'other-trend@example.com',
        ]);
        $otherShop = Shop::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Trend Shop',
        ]);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Daily',
            'price' => 9999,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        Payment::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'DASH-TREND-OTHER',
            'amount' => 9999,
            'gross_amount' => 9999,
            'platform_fee_amount' => 999,
            'tenant_net_amount' => 9000,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
            'payload' => [],
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Finance Trend')
            ->assertSee('Last 6 months')
            ->assertSee('Finance trend chart', false)
            ->assertSee('Gross')
            ->assertSee('Expense')
            ->assertSee('Profit')
            ->assertSee(now()->format('M Y'))
            ->assertSee('NGN 2,000.00')
            ->assertSee('NGN 1,800.00')
            ->assertSee('NGN 800.00')
            ->assertSee('NGN 1,000.00')
            ->assertSee('55.6%')
            ->assertSee('Full report')
            ->assertSee('Details')
            ->assertDontSee('NGN 9,999.00');
    }

    public function test_dashboard_shows_current_month_budget_watch(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $watchedCategory = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Generator fuel',
            'monthly_budget' => 1000,
            'is_active' => true,
        ]);
        $quietCategory = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Office supplies',
            'monthly_budget' => 1000,
            'is_active' => true,
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $watchedCategory->id,
            'title' => 'Diesel',
            'amount' => 850,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $quietCategory->id,
            'title' => 'Pens',
            'amount' => 250,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $watchedCategory->id,
            'title' => 'Old diesel',
            'amount' => 900,
            'currency' => 'NGN',
            'incurred_on' => now()->subMonth()->toDateString(),
        ]);

        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $otherCategory = ExpenseCategory::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other fuel',
            'monthly_budget' => 1000,
            'is_active' => true,
        ]);
        Expense::create([
            'tenant_id' => $otherTenant->id,
            'expense_category_id' => $otherCategory->id,
            'title' => 'Other diesel',
            'amount' => 950,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Budget Watch')
            ->assertSee('Generator fuel')
            ->assertSee('Near budget')
            ->assertSee('NGN 850.00')
            ->assertSee('NGN 1,000.00')
            ->assertSee('NGN 150.00')
            ->assertSee('85%')
            ->assertSee('View expenses')
            ->assertSee('Details')
            ->assertSee(route('admin.expenses.index', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfDay()->toDateString(),
            ]))
            ->assertSee(route('admin.expenses.index', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfDay()->toDateString(),
                'category' => $watchedCategory->id,
            ]))
            ->assertDontSee('Office supplies')
            ->assertDontSee('Other fuel')
            ->assertDontSee('NGN 950.00');
    }

    public function test_dashboard_shows_budget_watch_all_good_state(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Office supplies',
            'monthly_budget' => 1000,
            'is_active' => true,
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Pens',
            'amount' => 300,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Budget Watch')
            ->assertSee('1 budgeted categories are currently below the 80% watch threshold.')
            ->assertSee('All budgeted categories are under watch level.')
            ->assertSee('View expenses')
            ->assertDontSee('Near budget')
            ->assertDontSee('Over budget');
    }

    public function test_dashboard_shows_upcoming_recurring_expenses(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Own Tenant',
            'owner_email' => 'own@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        Expense::create([
            'tenant_id' => $ownTenant->id,
            'title' => 'Monthly upstream internet',
            'amount' => 25000,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => now()->addDays(10)->toDateString(),
        ]);
        Expense::create([
            'tenant_id' => $otherTenant->id,
            'title' => 'Other recurring cost',
            'amount' => 99000,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => now()->addDays(10)->toDateString(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Upcoming Recurring Expenses')
            ->assertSee('Monthly upstream internet')
            ->assertSee(route('admin.expenses.record-recurring', Expense::where('title', 'Monthly upstream internet')->first()), false)
            ->assertSee('Monthly')
            ->assertSee('NGN 25,000.00')
            ->assertDontSee('Other recurring cost')
            ->assertDontSee('NGN 99,000.00');
    }

    public function test_dashboard_highlights_overdue_recurring_expenses(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Own Tenant',
            'owner_email' => 'own@example.com',
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Overdue upstream internet',
            'amount' => 25000,
            'currency' => 'NGN',
            'incurred_on' => now()->subMonth()->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => now()->subDay()->toDateString(),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Overdue Recurring Expenses')
            ->assertSee('Overdue upstream internet')
            ->assertSee('Review overdue')
            ->assertSee(route('admin.expenses.index', ['schedule' => 'overdue']), false);
    }
}
