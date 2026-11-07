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
 * 2026-11-07, direct report: "pending transaction with no reference, stuck
 * some devices, how can we clean that". Covers hotspot:expire-abandoned-payments.
 */
class ExpireAbandonedPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_pending_payments_without_a_reference_are_marked_abandoned(): void
    {
        $old = $this->payment('OLD-NO-REF', 'monnify', now()->subHours(2));
        $recent = $this->payment('RECENT-NO-REF', 'monnify', now()->subMinutes(10));
        $withReference = $this->payment('OLD-WITH-REF', 'monnify', now()->subHours(2), 'MNFY|1');
        Http::fake();

        Artisan::call('hotspot:expire-abandoned-payments');

        $this->assertSame('abandoned', $old->refresh()->status);
        $this->assertNotNull(data_get($old->payload, 'abandoned_at'));
        $this->assertSame('pending', $recent->refresh()->status);
        $this->assertSame('pending', $withReference->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_paid_flutterwave_card_checkout_is_recovered_instead_of_abandoned(): void
    {
        $this->createRadiusTables();
        $payment = $this->payment('FLW-PAID', 'flutterwave', now()->subHours(2));
        $payment->update(['payload' => array_merge($payment->payload, ['flutterwave_checkout_version' => 'standard_v3'])]);
        config(['services.flutterwave.standard_base_url' => 'https://api.flutterwave.com/v3']);
        Http::fake([
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => ['id' => 98765, 'tx_ref' => 'FLW-PAID', 'status' => 'successful', 'amount' => 500, 'currency' => 'NGN'],
            ]),
        ]);

        Artisan::call('hotspot:expire-abandoned-payments');

        $this->assertSame('successful', $payment->refresh()->status);
        $this->assertDatabaseHas('subscriptions', ['payment_id' => $payment->id]);
    }

    public function test_an_unpaid_flutterwave_card_checkout_is_abandoned(): void
    {
        $payment = $this->payment('FLW-UNPAID', 'flutterwave', now()->subHours(2));
        $payment->update(['payload' => array_merge($payment->payload, ['flutterwave_checkout_version' => 'standard_v3'])]);
        config(['services.flutterwave.standard_base_url' => 'https://api.flutterwave.com/v3']);
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'error', 'message' => 'No transaction was found'], 404)]);

        Artisan::call('hotspot:expire-abandoned-payments');

        $this->assertSame('abandoned', $payment->refresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $payment = $this->payment('DRY-RUN', 'monnify', now()->subHours(2));

        Artisan::call('hotspot:expire-abandoned-payments', ['--dry-run' => true]);

        $this->assertSame('pending', $payment->refresh()->status);
    }

    private function payment(string $txRef, string $provider, $createdAt, ?string $providerReference = null): Payment
    {
        $tenant = Tenant::create(['company_name' => 'Abandoned '.$txRef, 'owner_email' => strtolower($txRef).'@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Abandoned Shop', 'flutterwave_secret_key' => 'FLWSECK_TEST-shop']);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $customer = Customer::create(['shop_id' => $shop->id, 'mac_address' => 'AA:BB:CC:DD:EE:'.substr(md5($txRef), 0, 2)]);

        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'tx_ref' => $txRef,
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 500,
            'commission_rate' => 0,
            'billing_model' => 'subscription',
            'currency' => 'NGN',
            'status' => 'pending',
            'payload' => ['mac' => $customer->mac_address],
        ]);
        $payment->forceFill(['created_at' => $createdAt])->save();

        return $payment;
    }

    private function createRadiusTables(): void
    {
        if (Schema::hasTable('radcheck')) {
            return;
        }

        foreach (['radcheck', 'radreply', 'radgroupreply'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('username')->nullable();
                $table->string('groupname')->nullable();
                $table->string('attribute');
                $table->string('op', 2);
                $table->string('value');
            });
        }

        Schema::create('radusergroup', function (Blueprint $table) {
            $table->string('username');
            $table->string('groupname');
            $table->integer('priority')->default(1);
        });
    }
}
