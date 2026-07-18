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
            ->assertSee('Revenue');
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

    private function paymentFixture(string $tenantName, string $ownerEmail, string $shopName, string $txRef, string $status): array
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
        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'provider' => 'flutterwave',
            'tx_ref' => $txRef,
            'provider_reference' => $status === 'successful' ? 'ord_'.$txRef : null,
            'amount' => 500,
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
