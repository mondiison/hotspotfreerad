<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminPaymentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_payment_report_across_tenants(): void
    {
        [$ownPayment] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Own Shop', 'OWN-REF', 'successful');
        [$otherPayment] = $this->paymentFixture('Other Tenant', 'other@example.com', 'Other Shop', 'OTHER-REF', 'pending');
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($ownPayment->tx_ref)
            ->assertSee($otherPayment->tx_ref)
            ->assertSee('NGN 500.00')
            ->assertSee('Transactions')
            ->assertSee('Gross Sales');
    }

    public function test_tenant_admin_only_sees_own_payments(): void
    {
        [$ownPayment, $ownTenant] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Own Shop', 'OWN-REF', 'successful');
        [$otherPayment] = $this->paymentFixture('Other Tenant', 'other@example.com', 'Other Shop', 'OTHER-REF', 'successful');
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($ownPayment->tx_ref)
            ->assertDontSee($otherPayment->tx_ref)
            ->assertSee('Own Shop')
            ->assertDontSee('Other Shop');
    }

    public function test_payment_report_can_filter_by_status_and_search(): void
    {
        [$successfulPayment] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Main Hall', 'SUCCESS-REF', 'successful');
        [$pendingPayment, $tenant] = $this->paymentFixture('Own Tenant Two', 'two@example.com', 'Annex', 'PENDING-REF', 'pending');
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index', [
                'status' => 'pending',
                'search' => 'Annex',
            ]))
            ->assertOk()
            ->assertSee($pendingPayment->tx_ref)
            ->assertDontSee($successfulPayment->tx_ref);
    }

    public function test_payment_report_shows_commission_totals(): void
    {
        [$payment] = $this->paymentFixture('Commission Tenant', 'commission@example.com', 'Commission Shop', 'COMMISSION-REF', 'successful', [
            'billing_model' => 'commission',
            'commission_rate' => 20,
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($payment->tx_ref)
            ->assertSee('Platform Commission')
            ->assertSee('Tenant Net')
            ->assertSee('NGN 100.00')
            ->assertSee('NGN 400.00')
            ->assertSee('20.00%');
    }

    public function test_payment_report_can_filter_by_date_presets_and_show_attention_totals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));

        try {
            [$recentPending] = $this->paymentFixture('Preset Tenant', 'preset@example.com', 'Recent Shop', 'RECENT-PENDING', 'pending');
            [$recentFailed] = $this->paymentFixture('Failed Preset Tenant', 'failed-preset@example.com', 'Failed Shop', 'RECENT-FAILED', 'failed');
            [$oldPayment] = $this->paymentFixture('Old Preset Tenant', 'old-preset@example.com', 'Old Shop', 'OLD-PENDING', 'pending');
            $oldPayment->update([
                'created_at' => '2026-07-01 10:00:00',
                'updated_at' => '2026-07-01 10:00:00',
            ]);

            $user = User::factory()->create([
                'role' => 'super_admin',
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get(route('admin.payments.index', [
                    'preset' => 'last_7_days',
                ]))
                ->assertOk()
                ->assertSee('7 days')
                ->assertSee('2026-07-13')
                ->assertSee('2026-07-19')
                ->assertSee($recentPending->tx_ref)
                ->assertSee($recentFailed->tx_ref)
                ->assertSee('Failed')
                ->assertSee('NGN 500.00 awaiting confirmation')
                ->assertSee('NGN 500.00 not confirmed')
                ->assertDontSee($oldPayment->tx_ref)
                ->assertDontSee('Old Shop');

            $this->actingAs($user)
                ->get(route('admin.payments.index', [
                    'from' => '2026-07-01',
                    'to' => '2026-07-02',
                ]))
                ->assertOk()
                ->assertSee($oldPayment->tx_ref)
                ->assertDontSee($recentPending->tx_ref);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_payment_report_attention_filter_groups_unresolved_payments(): void
    {
        [$pendingPayment] = $this->paymentFixture('Attention Tenant', 'attention@example.com', 'Pending Shop', 'ATTENTION-PENDING', 'pending');
        [$failedPayment] = $this->paymentFixture('Attention Failed Tenant', 'attention-failed@example.com', 'Failed Shop', 'ATTENTION-FAILED', 'failed');
        [$verificationPayment] = $this->paymentFixture('Attention Verification Tenant', 'attention-verification@example.com', 'Verification Shop', 'ATTENTION-VERIFY', 'verification_failed');
        [$successfulPayment] = $this->paymentFixture('Attention Success Tenant', 'attention-success@example.com', 'Success Shop', 'ATTENTION-SUCCESS', 'successful');
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index', [
                'status' => 'attention',
            ]))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee($pendingPayment->tx_ref)
            ->assertSee($failedPayment->tx_ref)
            ->assertSee($verificationPayment->tx_ref)
            ->assertDontSee($successfulPayment->tx_ref);
    }

    public function test_tenant_admin_can_export_filtered_payment_report(): void
    {
        [$ownPayment, $ownTenant] = $this->paymentFixture('Own Export Tenant', 'own-export@example.com', 'Own Export Shop', 'EXPORT-OWN', 'successful', [
            'billing_model' => 'commission',
            'commission_rate' => 20,
        ]);
        [$otherPayment] = $this->paymentFixture('Other Export Tenant', 'other-export@example.com', 'Other Export Shop', 'EXPORT-OTHER', 'successful');
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.payments.export', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfDay()->toDateString(),
                'status' => 'successful',
                'search' => 'Own Export',
            ]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Payment Report', $content);
        $this->assertStringContainsString('Status,Successful', $content);
        $this->assertStringContainsString('Search,"Own Export"', $content);
        $this->assertStringContainsString('"Transaction Ref","Provider Ref",Provider,Status', $content);
        $this->assertStringContainsString($ownPayment->tx_ref, $content);
        $this->assertStringContainsString('"Own Export Shop"', $content);
        $this->assertStringContainsString('"Own Export Tenant"', $content);
        $this->assertStringContainsString('500.00,100.00,400.00,20.00,commission,Yes', $content);
        $this->assertStringNotContainsString($otherPayment->tx_ref, $content);
        $this->assertStringNotContainsString('Other Export Shop', $content);
    }

    private function paymentFixture(string $tenantName, string $ownerEmail, string $shopName, string $txRef, string $status, array $tenantOverrides = []): array
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
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'shop_id' => $shop->id,
            'mac_address' => 'AA:BB:CC:DD:EE:'.substr($txRef, 0, 2),
            'email' => strtolower($txRef).'@example.com',
            'phone' => '08000000000',
        ]);
        $commissionRate = ($tenant->billing_model ?? 'subscription') === 'commission' ? (float) $tenant->commission_rate : 0.0;
        $platformFee = round(500 * ($commissionRate / 100), 2);

        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'provider' => 'flutterwave',
            'tx_ref' => $txRef,
            'provider_reference' => $status === 'successful' ? 'ord_'.$txRef : null,
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => $platformFee,
            'tenant_net_amount' => 500 - $platformFee,
            'commission_rate' => $commissionRate,
            'billing_model' => $tenant->billing_model ?? 'subscription',
            'currency' => 'NGN',
            'status' => $status,
            'paid_at' => $status === 'successful' ? now() : null,
            'payload' => ['mac' => $customer->mac_address],
        ]);

        if ($status === 'successful') {
            Subscription::create([
                'shop_id' => $shop->id,
                'package_id' => $package->id,
                'payment_id' => $payment->id,
                'mac_address' => $customer->mac_address,
                'starts_at' => now(),
                'expires_at' => now()->addHour(),
            ]);
        }

        return [$payment, $tenant, $shop, $package, $customer];
    }
}
