<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026-10-16, direct report: "I notice someone make payment and it's
 * after a day that i clicked verify that it became successful... want to
 * avoid that." Covers hotspot:retry-pending-payments -- the scheduled
 * command that re-checks a still-pending online payment automatically,
 * reusing the exact same HotspotPaymentConfirmationService::verifyAndGrant()
 * the manual "Verify" button already calls.
 */
class RetryPendingPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payment_due_for_its_first_retry_is_verified_and_confirmed(): void
    {
        $this->createRadiusTables();
        [$payment] = $this->paymentFixture('ONLINE-DUE-1');
        $payment->update([
            'provider_reference' => 'ord_due_1',
            'created_at' => now()->subMinutes(10),
        ]);
        $this->fakeFlutterwaveSuccess('ord_due_1', $payment->tx_ref);

        Artisan::call('hotspot:retry-pending-payments');

        $payment->refresh();
        $this->assertSame('successful', $payment->status);
        $this->assertSame(1, $payment->verification_attempts);
        $this->assertNotNull($payment->last_verification_attempt_at);
        $this->assertDatabaseHas('subscriptions', ['payment_id' => $payment->id]);
    }

    public function test_a_payment_not_yet_due_is_left_untouched(): void
    {
        [$payment] = $this->paymentFixture('ONLINE-NOT-DUE');
        $payment->update([
            'provider_reference' => 'ord_not_due',
            'created_at' => now()->subMinute(),
        ]);
        Http::fake();

        Artisan::call('hotspot:retry-pending-payments');

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, $payment->verification_attempts);
        Http::assertNothingSent();
    }

    public function test_a_payment_that_exhausted_all_three_attempts_is_never_retried_again(): void
    {
        [$payment] = $this->paymentFixture('ONLINE-EXHAUSTED');
        $payment->update([
            'provider_reference' => 'ord_exhausted',
            'created_at' => now()->subDays(10),
            'verification_attempts' => 3,
        ]);
        Http::fake();

        Artisan::call('hotspot:retry-pending-payments');

        $payment->refresh();
        $this->assertSame(3, $payment->verification_attempts);
        Http::assertNothingSent();
    }

    public function test_manual_bank_and_voucher_cash_payments_are_never_auto_retried(): void
    {
        [$manualPayment] = $this->paymentFixture('MANUAL-BANK-OLD', 'manual_bank');
        $manualPayment->update([
            'provider_reference' => 'manual-ref',
            'created_at' => now()->subDays(1),
        ]);
        [$voucherPayment] = $this->paymentFixture('VOUCHER-OLD', 'voucher_cash', 'pending');
        $voucherPayment->update([
            'provider_reference' => 'voucher-ref',
            'created_at' => now()->subDays(1),
        ]);
        Http::fake();

        Artisan::call('hotspot:retry-pending-payments');

        $this->assertSame(0, $manualPayment->refresh()->verification_attempts);
        $this->assertSame(0, $voucherPayment->refresh()->verification_attempts);
        Http::assertNothingSent();
    }

    public function test_dry_run_reports_candidates_without_changing_anything(): void
    {
        [$payment] = $this->paymentFixture('ONLINE-DRY-RUN');
        $payment->update([
            'provider_reference' => 'ord_dry_run',
            'created_at' => now()->subMinutes(10),
        ]);
        Http::fake();

        Artisan::call('hotspot:retry-pending-payments', ['--dry-run' => true]);

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, $payment->verification_attempts);
        Http::assertNothingSent();
    }

    public function test_a_payment_still_not_confirmed_by_the_gateway_increments_attempts_without_succeeding(): void
    {
        [$payment] = $this->paymentFixture('ONLINE-STILL-PENDING');
        $payment->update([
            'provider_reference' => 'ord_still_pending',
            'created_at' => now()->subMinutes(10),
        ]);
        config([
            'services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
            'services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com',
        ]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response(['access_token' => 'FLW_V4_TOKEN', 'expires_in' => 600]),
            'developersandbox-api.flutterwave.com/orders/ord_still_pending' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_still_pending',
                    'status' => 'pending',
                    'reference' => $payment->tx_ref,
                    'amount' => 500,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        Artisan::call('hotspot:retry-pending-payments');

        $payment->refresh();
        $this->assertNotSame('successful', $payment->status);
        $this->assertSame(1, $payment->verification_attempts);
    }

    private function fakeFlutterwaveSuccess(string $providerReference, string $txRef): void
    {
        config([
            'services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
            'services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com',
        ]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response(['access_token' => 'FLW_V4_TOKEN', 'expires_in' => 600]),
            "developersandbox-api.flutterwave.com/orders/{$providerReference}" => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => $providerReference,
                    'status' => 'succeeded',
                    'reference' => $txRef,
                    'amount' => 500,
                    'currency' => 'NGN',
                ],
            ]),
        ]);
    }

    private function paymentFixture(string $txRef, string $provider = 'flutterwave', string $status = 'pending'): array
    {
        $tenant = Tenant::create([
            'company_name' => 'Retry Tenant '.$txRef,
            'owner_email' => strtolower($txRef).'@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Retry Shop',
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
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
            'provider' => $provider,
            'tx_ref' => $txRef,
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 500,
            'commission_rate' => 0,
            'billing_model' => 'subscription',
            'currency' => 'NGN',
            'status' => $status,
            'payload' => ['mac' => $customer->mac_address],
        ]);

        return [$payment, $tenant, $shop, $package, $customer];
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
