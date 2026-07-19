<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Package;
use App\Models\Payment;
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
            ->assertSee(route('admin.shops.index'), false)
            ->assertSee(route('admin.routers.index'), false)
            ->assertSee(route('tenant.public-site', $ownTenant), false)
            ->assertSee('Own Router')
            ->assertDontSee('Other Router');
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
            ->assertDontSee('NGN 9,999.00');
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
