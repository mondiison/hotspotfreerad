<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSalesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_report_sales_by_day_month_and_year(): void
    {
        $this->paymentFixture('Mondi Tenant', 'owner@example.com', 'Main Hall', 1000, 'successful', '2026-01-05 10:00:00');
        $this->paymentFixture('Mondi Tenant Two', 'two@example.com', 'Annex', 2500, 'successful', '2026-01-20 10:00:00');
        $this->paymentFixture('Mondi Tenant Three', 'three@example.com', 'Old Shop', 9000, 'successful', '2025-12-31 10:00:00');
        $this->paymentFixture('Mondi Tenant Four', 'four@example.com', 'Pending Shop', 5000, 'pending', null);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.reports.sales', [
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'group' => 'month',
            ]))
            ->assertOk()
            ->assertSee('Sales Report')
            ->assertSee('2026-01')
            ->assertSee('NGN 3,500.00')
            ->assertSee('NGN 1,750.00')
            ->assertSee('71.4%')
            ->assertSee('28.6%')
            ->assertSee('Main Hall')
            ->assertSee('Annex')
            ->assertDontSee('Old Shop')
            ->assertDontSee('Pending Shop');
    }

    public function test_tenant_admin_only_reports_own_sales(): void
    {
        [$ownPayment, $ownTenant] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Own Shop', 1500, 'successful', '2026-02-01 10:00:00');
        $this->paymentFixture('Other Tenant', 'other@example.com', 'Other Shop', 9000, 'successful', '2026-02-01 10:00:00');
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.reports.sales', [
                'from' => '2026-02-01',
                'to' => '2026-02-28',
                'group' => 'day',
            ]))
            ->assertOk()
            ->assertSee('2026-02-01')
            ->assertSee('NGN 1,500.00')
            ->assertSee('Own Shop')
            ->assertDontSee('NGN 9,000.00')
            ->assertDontSee('Other Shop');

        $this->assertSame('successful', $ownPayment->status);
    }

    public function test_sales_report_shows_platform_commission_and_tenant_net(): void
    {
        $this->paymentFixture('Commission Tenant', 'commission@example.com', 'Commission Shop', 2000, 'successful', '2026-04-05 10:00:00', [
            'billing_model' => 'commission',
            'commission_rate' => 15,
        ]);
        $tenant = Tenant::where('owner_email', 'commission@example.com')->firstOrFail();
        $category = ExpenseCategory::where('name', 'Maintenance')->firstOrFail();
        $category->update(['monthly_budget' => 1000]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Router maintenance',
            'amount' => 500,
            'currency' => 'NGN',
            'incurred_on' => '2026-04-07',
        ]);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.reports.sales', [
                'from' => '2026-04-01',
                'to' => '2026-04-30',
                'group' => 'month',
            ]))
            ->assertOk()
            ->assertSee('Gross Sales')
            ->assertSee('Platform Commission')
            ->assertSee('Tenant Net')
            ->assertSee('Expenses')
            ->assertSee('Estimated Profit')
            ->assertSee('Profit Margin')
            ->assertSee('Profit')
            ->assertSee('Margin')
            ->assertSee('Budget')
            ->assertSee('Variance')
            ->assertSee('Usage')
            ->assertSee('NGN 2,000.00')
            ->assertSee('NGN 300.00')
            ->assertSee('NGN 1,700.00')
            ->assertSee('NGN 500.00')
            ->assertSee('NGN 1,000.00')
            ->assertSee('NGN 1,200.00')
            ->assertSee('50%')
            ->assertSee('70.6%')
            ->assertSee('Maintenance');
    }

    public function test_sales_report_can_use_date_presets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));

        try {
            $this->paymentFixture('Preset Tenant', 'preset@example.com', 'Recent Shop', 1800, 'successful', '2026-07-14 10:00:00');
            $this->paymentFixture('Old Preset Tenant', 'old-preset@example.com', 'Old Shop', 9000, 'successful', '2026-07-01 10:00:00');

            $user = User::factory()->create([
                'role' => 'super_admin',
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get(route('admin.reports.sales', [
                    'preset' => 'last_7_days',
                ]))
                ->assertOk()
                ->assertSee('7 days')
                ->assertSee('2026-07-13')
                ->assertSee('2026-07-19')
                ->assertSee('Recent Shop')
                ->assertSee('NGN 1,800.00')
                ->assertDontSee('Old Shop')
                ->assertDontSee('NGN 9,000.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sales_report_validates_date_range(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.reports.sales', [
                'from' => '2026-03-10',
                'to' => '2026-03-01',
            ]))
            ->assertSessionHasErrors('to');
    }

    public function test_sales_report_can_be_exported_as_csv(): void
    {
        $this->paymentFixture('CSV Tenant', 'csv@example.com', 'CSV Shop', 3000, 'successful', '2026-05-05 10:00:00', [
            'billing_model' => 'commission',
            'commission_rate' => 10,
        ]);
        $tenant = Tenant::where('owner_email', 'csv@example.com')->firstOrFail();
        $category = ExpenseCategory::where('name', 'Equipment')->firstOrFail();
        $category->update(['monthly_budget' => 1000]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Access point',
            'amount' => 800,
            'currency' => 'NGN',
            'incurred_on' => '2026-05-06',
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.reports.sales.export', [
                'from' => '2026-05-01',
                'to' => '2026-05-31',
                'group' => 'month',
            ]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Sales Report', $content);
        $this->assertStringContainsString('"Gross Sales",3000.00', $content);
        $this->assertStringContainsString('"Platform Commission",300.00', $content);
        $this->assertStringContainsString('"Tenant Net",2700.00', $content);
        $this->assertStringContainsString('Expenses,800.00', $content);
        $this->assertStringContainsString('"Estimated Profit",1900.00', $content);
        $this->assertStringContainsString('"Profit Margin",70.4%', $content);
        $this->assertStringContainsString('"Sales by Period"', $content);
        $this->assertStringContainsString('Period,Sales,"Average Sale","Gross Sales","Platform Commission","Tenant Net",Expenses,"Estimated Profit","Profit Margin"', $content);
        $this->assertStringContainsString('2026-05,1,3000.00,3000.00,300.00,2700.00,800.00,1900.00,70.4%', $content);
        $this->assertStringContainsString('Shop,Sales,"Gross Sales",Share,"Platform Commission","Tenant Net"', $content);
        $this->assertStringContainsString('"CSV Shop",1,3000.00,100%,300.00,2700.00', $content);
        $this->assertStringContainsString('Category,Count,Amount,Budget,Variance,Usage', $content);
        $this->assertStringContainsString('Equipment,1,800.00,1000.00,200.00,80%', $content);
        $this->assertStringContainsString('CSV Shop', $content);
        $this->assertStringContainsString('Equipment', $content);
    }

    private function paymentFixture(string $tenantName, string $ownerEmail, string $shopName, int $amount, string $status, ?string $paidAt, array $tenantOverrides = []): array
    {
        $tenant = Tenant::create(array_merge([
            'company_name' => $tenantName,
            'owner_email' => $ownerEmail,
        ], $tenantOverrides));
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $shopName,
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily Access',
            'price' => $amount,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'shop_id' => $shop->id,
            'mac_address' => fake()->unique()->macAddress(),
        ]);
        $commissionRate = ($tenant->billing_model ?? 'subscription') === 'commission' ? (float) $tenant->commission_rate : 0.0;
        $platformFee = round($amount * ($commissionRate / 100), 2);

        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'provider' => 'flutterwave',
            'tx_ref' => fake()->unique()->bothify('TX-####'),
            'provider_reference' => $status === 'successful' ? fake()->unique()->bothify('ORD-####') : null,
            'amount' => $amount,
            'gross_amount' => $amount,
            'platform_fee_amount' => $platformFee,
            'tenant_net_amount' => $amount - $platformFee,
            'commission_rate' => $commissionRate,
            'billing_model' => $tenant->billing_model ?? 'subscription',
            'currency' => 'NGN',
            'status' => $status,
            'paid_at' => $paidAt,
            'payload' => [],
            'created_at' => $paidAt ?? now(),
            'updated_at' => $paidAt ?? now(),
        ]);

        return [$payment, $tenant, $shop, $package, $customer];
    }
}
