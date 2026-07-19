<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function paymentFixture(string $tenantName, string $ownerEmail, string $shopName, int $amount, string $status, ?string $paidAt): array
    {
        $tenant = Tenant::create([
            'company_name' => $tenantName,
            'owner_email' => $ownerEmail,
        ]);
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
        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'provider' => 'flutterwave',
            'tx_ref' => fake()->unique()->bothify('TX-####'),
            'provider_reference' => $status === 'successful' ? fake()->unique()->bothify('ORD-####') : null,
            'amount' => $amount,
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
