<?php

namespace Tests\Feature;

use App\Jobs\SyncRouterWalledGarden;
use App\Livewire\Admin\WalletIndex;
use App\Models\BillingPlan;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletWithdrawal;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_ineligible_tenant_admin_sees_the_upgrade_message_not_the_enable_form(): void
    {
        $tenant = $this->tenant();
        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->get(route('admin.wallet.index'))
            ->assertOk()
            ->assertSee('Enable the platform wallet')
            ->assertSee('Your tenant needs an active platform billing subscription before enabling the wallet.')
            ->assertDontSee('Enable Wallet');
    }

    public function test_super_admin_cannot_view_the_tenant_wallet_page(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($superAdmin)
            ->get(route('admin.wallet.index'))
            ->assertForbidden();
    }

    public function test_tenant_staff_cannot_view_the_wallet_page(): void
    {
        $tenant = $this->tenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('admin.wallet.index'))
            ->assertForbidden();
    }

    public function test_eligible_tenant_admin_can_enable_the_wallet(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 7.5);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->call('enableWallet')
            ->assertDispatched('toast-show');

        $tenant->refresh();
        $this->assertTrue($tenant->wallet_enabled);
        $this->assertEquals(7.5, $tenant->commission_rate);
        $this->assertDatabaseHas('wallets', ['tenant_id' => $tenant->id]);
    }

    /**
     * Regression test for a 2026-09-25 live report: enabling wallet mode
     * changes what gateway Shop::paymentGateway() resolves to without ever
     * touching the shop's payment_gateway column, so it never went through
     * PaymentSettingsService's existing "gateway changed, re-sync walled
     * gardens" dispatch -- a tenant enabled wallet (Monnify showed correctly
     * on checkout) but the router's walled garden still only allowed the
     * previous gateway, so the payment redirect got hard-reset
     * (net::ERR_CONNECTION_CLOSED).
     */
    public function test_enabling_wallet_dispatches_a_walled_garden_sync_for_every_shop_router(): void
    {
        Queue::fake();

        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 7.5);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Router One',
            'nas_identifier' => 'wallet-enable-router',
            'wireguard_internal_ip' => '10.8.0.210',
            'shared_secret' => 'radius-secret',
        ]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->call('enableWallet');

        Queue::assertPushed(SyncRouterWalledGarden::class, fn (SyncRouterWalledGarden $job): bool => $job->routerId() === $router->id);
    }

    public function test_tenant_admin_can_change_who_bears_the_commission(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        Wallet::create(['tenant_id' => $tenant->id, 'balance' => 0]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('commissionBearer', 'customer')
            ->call('saveCommissionBearer');

        $this->assertSame('customer', $tenant->fresh()->wallet_commission_bearer);
    }

    public function test_tenant_admin_can_request_a_withdrawal_within_the_wallet_balance(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        $this->giveTenantAVerifiedSettlementAccount($tenant);
        $wallet = Wallet::create(['tenant_id' => $tenant->id, 'balance' => 1000]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('withdrawAmount', '400')
            ->call('requestWithdrawal')
            ->assertDispatched('toast-show')
            ->assertSet('showWithdrawModal', false);

        $this->assertDatabaseHas('wallet_withdrawals', [
            'tenant_id' => $tenant->id,
            'wallet_id' => $wallet->id,
            'amount' => 400,
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'Demo Tenant',
            'status' => 'pending',
        ]);
        $this->assertEquals(600, $wallet->fresh()->balance);
    }

    /**
     * 2026-09-28, direct request: bank charges must apply to every
     * withdrawal, on top of the amount the tenant asks to receive, and the
     * tenant must be able to see the fee before submitting.
     */
    public function test_a_fixed_withdrawal_fee_is_debited_on_top_of_the_requested_amount(): void
    {
        PlatformSetting::query()->create(['key' => 'wallet.withdrawal_fee', 'value' => ['type' => 'fixed', 'value' => 40]]);

        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        $this->giveTenantAVerifiedSettlementAccount($tenant);
        $wallet = Wallet::create(['tenant_id' => $tenant->id, 'balance' => 500]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('withdrawAmount', '450')
            ->assertSee('40.00')
            ->assertSee('490.00')
            ->call('requestWithdrawal')
            ->assertDispatched('toast-show');

        $this->assertDatabaseHas('wallet_withdrawals', [
            'tenant_id' => $tenant->id,
            'amount' => 450,
            'fee_amount' => 40,
        ]);
        $this->assertEquals(10, $wallet->fresh()->balance);
    }

    public function test_a_percentage_withdrawal_fee_scales_with_the_requested_amount(): void
    {
        PlatformSetting::query()->create(['key' => 'wallet.withdrawal_fee', 'value' => ['type' => 'percentage', 'value' => 10]]);

        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        $this->giveTenantAVerifiedSettlementAccount($tenant);
        $wallet = Wallet::create(['tenant_id' => $tenant->id, 'balance' => 1000]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('withdrawAmount', '400')
            ->call('requestWithdrawal')
            ->assertDispatched('toast-show');

        $this->assertDatabaseHas('wallet_withdrawals', [
            'tenant_id' => $tenant->id,
            'amount' => 400,
            'fee_amount' => 40,
        ]);
        $this->assertEquals(560, $wallet->fresh()->balance);
    }

    public function test_withdrawal_is_rejected_when_the_fee_would_push_it_past_the_balance(): void
    {
        PlatformSetting::query()->create(['key' => 'wallet.withdrawal_fee', 'value' => ['type' => 'fixed', 'value' => 40]]);

        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        $this->giveTenantAVerifiedSettlementAccount($tenant);
        $wallet = Wallet::create(['tenant_id' => $tenant->id, 'balance' => 500]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('withdrawAmount', '480')
            ->call('requestWithdrawal')
            ->assertHasErrors('withdrawAmount');

        $this->assertDatabaseMissing('wallet_withdrawals', ['tenant_id' => $tenant->id]);
        $this->assertEquals(500, $wallet->fresh()->balance);
    }

    /**
     * 2026-09-28, direct request: the transaction history and withdrawal
     * requests lists had no way to filter at all.
     */
    public function test_transaction_history_can_be_filtered_by_type(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        $wallet = Wallet::create(['tenant_id' => $tenant->id, 'balance' => 1000]);
        app(WalletService::class)->applyEntry($wallet, 'credit', 200, 'Payment received');
        app(WalletService::class)->applyEntry($wallet, 'debit', 50, 'Platform commission');
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->assertSee('Payment received')
            ->assertSee('Platform commission')
            ->set('txType', 'credit')
            ->assertSee('Payment received')
            ->assertDontSee('Platform commission');
    }

    public function test_withdrawal_requests_can_be_filtered_by_status(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        $this->giveTenantAVerifiedSettlementAccount($tenant);
        Wallet::create(['tenant_id' => $tenant->id, 'balance' => 1000]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('withdrawAmount', '100')
            ->call('requestWithdrawal');

        WalletWithdrawal::query()->where('tenant_id', $tenant->id)->update(['status' => 'paid']);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->assertSee('Paid')
            ->set('withdrawalStatus', 'pending')
            ->assertSee('No withdrawal requests match this view.');
    }

    public function test_withdrawal_request_is_blocked_without_a_verified_settlement_account(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        Wallet::create(['tenant_id' => $tenant->id, 'balance' => 1000]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('withdrawAmount', '400')
            ->call('requestWithdrawal')
            ->assertHasErrors('withdrawAmount');

        $this->assertDatabaseMissing('wallet_withdrawals', ['tenant_id' => $tenant->id]);
    }

    /**
     * 2026-09-25, direct request: a tenant should be able to verify and save
     * a settlement account once (via a real bank-resolve API call) instead of
     * retyping bank details on every withdrawal request.
     */
    public function test_tenant_admin_can_verify_and_save_a_settlement_account(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        Wallet::create(['tenant_id' => $tenant->id, 'balance' => 0]);
        $user = $this->tenantAdmin($tenant);

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.gateway.paystack'],
            ['value' => ['secret_key' => Crypt::encryptString('sk_test_platform')]]
        );
        Cache::flush();

        Http::fake([
            'api.paystack.co/bank?*' => Http::response([
                'status' => true,
                'data' => [
                    ['code' => '058', 'name' => 'GTBank'],
                ],
            ]),
            'api.paystack.co/bank/resolve*' => Http::response([
                'status' => true,
                'data' => ['account_number' => '0123456789', 'account_name' => 'Demo Tenant'],
            ]),
        ]);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('selectedBankCode', '058')
            ->set('settlementAccountNumber', '0123456789')
            ->call('verifySettlementAccount')
            ->assertSet('resolvedAccountName', 'Demo Tenant')
            ->call('saveSettlementAccount')
            ->assertDispatched('toast-show');

        $tenant->refresh();
        $this->assertSame('058', $tenant->settlement_bank_code);
        $this->assertSame('GTBank', $tenant->settlement_bank_name);
        $this->assertSame('0123456789', $tenant->settlement_account_number);
        $this->assertSame('Demo Tenant', $tenant->settlement_account_name);
        $this->assertNotNull($tenant->settlement_verified_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bank/resolve')
            && $request->hasHeader('Authorization', 'Bearer sk_test_platform')
            && $request['account_number'] === '0123456789'
            && $request['bank_code'] === '058');
    }

    /**
     * Regression test for a 2026-09-25 live report: every account failed to
     * resolve because Monnify's v1 disbursement account-validate endpoint is
     * deprecated (confirmed live via the exact "responseCode: 99" rejection).
     * Locks in the v2 path this was fixed to use.
     */
    public function test_settlement_account_resolves_via_monnify_v2_endpoint(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        Wallet::create(['tenant_id' => $tenant->id, 'balance' => 0]);
        $user = $this->tenantAdmin($tenant);

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.gateway.monnify'],
            ['value' => [
                'public_key' => Crypt::encryptString('platform-monnify-api-key'),
                'secret_key' => Crypt::encryptString('platform-monnify-secret-key'),
                'contract_code' => Crypt::encryptString('platform-contract-code'),
            ]]
        );
        Cache::flush();

        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response([
                'responseBody' => ['accessToken' => 'SETTLEMENT_MONNIFY_TOKEN'],
            ]),
            'sandbox.monnify.com/api/v2/disbursements/account/validate*' => Http::response([
                'requestSuccessful' => true,
                'responseBody' => ['accountNumber' => '0148556206', 'accountName' => 'Monday Bulus', 'bankCode' => '058'],
            ]),
        ]);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('selectedBankCode', '058')
            ->set('settlementAccountNumber', '0148556206')
            ->call('verifySettlementAccount')
            ->assertSet('resolvedAccountName', 'Monday Bulus');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v2/disbursements/account/validate')
            && $request->hasHeader('Authorization', 'Bearer SETTLEMENT_MONNIFY_TOKEN'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/disbursements/account/validate'));
    }

    private function giveTenantAVerifiedSettlementAccount(Tenant $tenant): void
    {
        $tenant->forceFill([
            'settlement_bank_code' => '058',
            'settlement_bank_name' => 'GTBank',
            'settlement_account_number' => '0123456789',
            'settlement_account_name' => 'Demo Tenant',
            'settlement_verified_at' => now(),
        ])->save();
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);
    }

    private function tenantAdmin(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
    }

    private function subscribeTenant(Tenant $tenant, bool $supportsWallet = false, ?float $walletCommissionRate = null): TenantBillingSubscription
    {
        $plan = BillingPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
            'supports_wallet' => $supportsWallet,
            'wallet_commission_rate' => $walletCommissionRate,
            'is_active' => true,
        ]);

        return TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
    }
}
