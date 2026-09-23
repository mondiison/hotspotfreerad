<?php

namespace Tests\Unit;

use App\Models\BillingPlan;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithPlan(float $walletCommissionRate = 10): Tenant
    {
        $tenant = Tenant::create([
            'company_name' => fake()->unique()->company(),
            'owner_email' => fake()->unique()->safeEmail(),
        ]);

        $plan = BillingPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
            'supports_wallet' => true,
            'wallet_commission_rate' => $walletCommissionRate,
            'is_active' => true,
        ]);

        TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        return $tenant;
    }

    private function payment(Tenant $tenant, float $grossAmount, float $feeAmount, float $netAmount): Payment
    {
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily Plan',
            'price' => $grossAmount,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $customer = Customer::create(['shop_id' => $shop->id, 'mac_address' => 'AA:BB:CC:DD:EE:FF']);

        return Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'HSF-TEST-'.uniqid(),
            'amount' => $grossAmount,
            'gross_amount' => $grossAmount,
            'platform_fee_amount' => $feeAmount,
            'tenant_net_amount' => $netAmount,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'currency' => 'NGN',
            'status' => 'successful',
            'payload' => ['mac' => 'AA:BB:CC:DD:EE:FF'],
        ]);
    }

    public function test_enable_sets_billing_model_and_commission_rate_from_the_plan_and_creates_the_wallet(): void
    {
        $tenant = $this->tenantWithPlan(walletCommissionRate: 7.5);

        $wallet = app(WalletService::class)->enable($tenant);

        $tenant->refresh();
        $this->assertTrue($tenant->wallet_enabled);
        $this->assertSame('commission', $tenant->billing_model);
        $this->assertEquals(7.5, $tenant->commission_rate);
        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertDatabaseHas('wallets', ['tenant_id' => $tenant->id, 'balance' => 0]);
    }

    public function test_credit_for_payment_writes_two_ledger_rows_and_nets_the_tenant_amount(): void
    {
        $tenant = $this->tenantWithPlan();
        app(WalletService::class)->enable($tenant);

        $payment = $this->payment($tenant, grossAmount: 500, feeAmount: 50, netAmount: 450);

        app(WalletService::class)->creditForPayment($payment);

        $wallet = Wallet::where('tenant_id', $tenant->id)->first();
        $this->assertEquals(450, $wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'payment_id' => $payment->id,
            'type' => 'credit',
            'amount' => 500,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'payment_id' => $payment->id,
            'type' => 'debit',
            'amount' => 50,
        ]);
        $this->assertSame(2, $wallet->transactions()->count());
    }

    public function test_credit_for_payment_writes_only_one_row_when_there_is_no_commission(): void
    {
        $tenant = $this->tenantWithPlan(walletCommissionRate: 0);
        app(WalletService::class)->enable($tenant);

        $payment = $this->payment($tenant, grossAmount: 500, feeAmount: 0, netAmount: 500);

        app(WalletService::class)->creditForPayment($payment);

        $wallet = Wallet::where('tenant_id', $tenant->id)->first();
        $this->assertEquals(500, $wallet->balance);
        $this->assertSame(1, $wallet->transactions()->count());
    }

    public function test_balance_reads_the_tenants_wallet(): void
    {
        $tenant = $this->tenantWithPlan();
        app(WalletService::class)->enable($tenant);
        $payment = $this->payment($tenant, grossAmount: 200, feeAmount: 20, netAmount: 180);
        app(WalletService::class)->creditForPayment($payment);

        $this->assertEquals(180, app(WalletService::class)->balance($tenant->fresh('wallet')));
    }
}
