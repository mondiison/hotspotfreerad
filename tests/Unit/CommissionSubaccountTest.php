<?php

namespace Tests\Unit;

use App\Livewire\Admin\TenantsIndex;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\GatewayCredentialResolver;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\PlatformPaymentSettingsService;
use App\Services\TenantManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-10-10: covers automated platform commission collection on a
 * tenant's OWN gateway -- the mirror image of SubaccountSettlementTest
 * (wallet mode). See CLAUDE.md's "Automated platform commission
 * collection on a tenant's own gateway" entry. The two things most
 * worth getting backwards -- whose secret key creates the subaccount,
 * and the percentage_charge inversion -- each get an explicit assertion
 * below, not just "a subaccount code got stored."
 */
class CommissionSubaccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_omits_subaccount_code_for_every_tenant_without_one(): void
    {
        $tenant = $this->paystackTenant();
        $payment = $this->paystackPayment($tenant);

        $credentials = app(GatewayCredentialResolver::class)->forPayment($payment, 'paystack');

        $this->assertFalse($credentials->has('subaccount_code'));
        $this->assertSame('tenant-secret-key', $credentials->get('secret_key'));
    }

    public function test_resolver_merges_commission_subaccount_code_for_the_opted_in_tenant_only(): void
    {
        $tenant = $this->paystackTenant([
            'commission_subaccount_gateway' => 'paystack',
            'commission_subaccount_code' => 'ACCT_platform123',
        ]);
        $payment = $this->paystackPayment($tenant);

        $credentials = app(GatewayCredentialResolver::class)->forPayment($payment, 'paystack');

        $this->assertSame('ACCT_platform123', $credentials->get('subaccount_code'));
        // Still the tenant's own secret key -- the platform never
        // becomes the main account here, only a subaccount on it.
        $this->assertSame('tenant-secret-key', $credentials->get('secret_key'));
    }

    public function test_resolver_does_not_merge_for_a_wallet_enabled_tenant(): void
    {
        $tenant = $this->paystackTenant([
            'wallet_enabled' => true,
            'commission_subaccount_gateway' => 'paystack',
            'commission_subaccount_code' => 'ACCT_platform123',
        ]);
        $payment = $this->paystackPayment($tenant);

        $credentials = app(GatewayCredentialResolver::class)->forPayment($payment, 'paystack');

        // Wallet mode takes the OTHER branch entirely (platform-is-main),
        // which has no reason to read commission_subaccount_code at all.
        $this->assertFalse($credentials->has('subaccount_code'));
    }

    public function test_create_commission_subaccount_requires_commission_billing(): void
    {
        $tenant = $this->paystackTenant(['billing_model' => 'subscription', 'commission_rate' => 0]);

        $this->expectException(ValidationException::class);

        app(TenantManagementService::class)->createCommissionSubaccount($tenant, $this->superAdmin());
    }

    public function test_create_commission_subaccount_requires_a_tenant_paystack_secret_key(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'No Credentials Ltd',
            'owner_email' => 'owner-'.uniqid().'@example.com',
            'billing_model' => 'commission',
            'commission_rate' => 20,
        ]);

        $this->expectException(ValidationException::class);

        app(TenantManagementService::class)->createCommissionSubaccount($tenant, $this->superAdmin());
    }

    public function test_create_commission_subaccount_requires_a_verified_platform_settlement_account(): void
    {
        $tenant = $this->paystackTenant();

        $this->expectException(ValidationException::class);

        app(TenantManagementService::class)->createCommissionSubaccount($tenant, $this->superAdmin());
    }

    public function test_create_commission_subaccount_uses_the_tenants_own_key_and_the_platforms_own_bank_with_inverted_percentage(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        config(['app.name' => 'HotspotFreeRAD']);
        $this->configurePlatformSettlementAccount();
        $tenant = $this->paystackTenant(['commission_rate' => 20]);

        Http::fake([
            'api.paystack.co/subaccount' => Http::response([
                'status' => true,
                'data' => ['subaccount_code' => 'ACCT_platform456'],
            ]),
        ]);

        app(TenantManagementService::class)->createCommissionSubaccount($tenant, $this->superAdmin());

        Http::assertSent(function ($request) {
            // The TENANT's own secret key authorizes the call (Authorization
            // header), not the platform's -- this is what makes the platform
            // end up as a subaccount ON the tenant's account.
            $usesTenantKey = $request->hasHeader('Authorization', 'Bearer tenant-secret-key');

            // The PLATFORM's own bank details are what gets registered --
            // never the tenant's.
            $registersPlatformBank = $request->data()['settlement_bank'] === '058'
                && $request->data()['account_number'] === '0000000001';

            // percentage_charge is the MAIN account's (the tenant's) share
            // -- 100 - commission_rate, not commission_rate directly. Get
            // this backwards and the platform would keep the big cut.
            $percentageIsInverted = $request->data()['percentage_charge'] === 80.0;

            return $usesTenantKey && $registersPlatformBank && $percentageIsInverted;
        });

        $tenant->refresh();
        $this->assertSame('paystack', $tenant->commission_subaccount_gateway);
        $this->assertSame('ACCT_platform456', $tenant->commission_subaccount_code);
        $this->assertNotNull($tenant->commission_subaccount_created_at);
        $this->assertTrue($tenant->hasCommissionSubaccount());
    }

    public function test_platform_settlement_account_round_trips(): void
    {
        $settings = app(PlatformPaymentSettingsService::class);
        $this->assertFalse($settings->hasVerifiedSettlementAccount());

        $settings->updateSettlementAccount([
            'bank_code' => '058',
            'bank_name' => 'GTBank',
            'account_number' => '0000000001',
            'account_name' => 'HotspotFreeRAD Ltd',
        ], $this->superAdmin());

        $this->assertTrue($settings->hasVerifiedSettlementAccount());
        $this->assertSame('0000000001', $settings->settlementAccount()['account_number']);
    }

    public function test_checkout_includes_subaccount_param_only_for_the_opted_in_tenant(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'ref-1'],
            ]),
        ]);

        $tenant = $this->paystackTenant([
            'commission_subaccount_gateway' => 'paystack',
            'commission_subaccount_code' => 'ACCT_platform789',
        ]);
        $payment = $this->paystackPayment($tenant);
        $credentials = app(GatewayCredentialResolver::class)->forPayment($payment, 'paystack');

        app(PaystackGateway::class)->initializeCheckout($credentials, new ChargeRequest(
            reference: 'ref-1',
            amount: 500,
            currency: 'NGN',
            redirectUrl: 'https://example.test/callback',
            customerEmail: 'customer@example.com',
            customerName: 'Customer',
            description: 'Test',
        ));

        Http::assertSent(fn ($request) => ($request->data()['subaccount'] ?? null) === 'ACCT_platform789');
    }

    public function test_tenants_index_can_enable_commission_subaccount(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        config(['app.name' => 'HotspotFreeRAD']);
        $this->configurePlatformSettlementAccount();
        Http::fake([
            'api.paystack.co/subaccount' => Http::response([
                'status' => true,
                'data' => ['subaccount_code' => 'ACCT_live999'],
            ]),
        ]);

        $tenant = $this->paystackTenant(['commission_rate' => 15]);
        $actor = $this->superAdmin();

        Livewire::actingAs($actor)
            ->test(TenantsIndex::class)
            ->call('edit', $tenant->id)
            ->call('createCommissionSubaccount')
            ->assertSet('commissionSubaccountError', null);

        $tenant->refresh();
        $this->assertSame('ACCT_live999', $tenant->commission_subaccount_code);
    }

    private function paystackTenant(array $overrides = []): Tenant
    {
        $tenant = Tenant::create(array_merge([
            'company_name' => 'Own Gateway Tenant '.uniqid(),
            'owner_email' => 'owner-'.uniqid().'@example.com',
            'billing_model' => 'commission',
            'commission_rate' => 20,
        ], $overrides));

        $tenant->forceFill([
            'payment_gateway_settings' => [
                'paystack' => ['secret_key' => 'tenant-secret-key', 'public_key' => 'tenant-public-key'],
            ],
        ])->save();

        return $tenant->refresh();
    }

    private function paystackPayment(Tenant $tenant): Payment
    {
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Shop '.uniqid(), 'payment_gateway' => 'paystack']);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);

        return Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'provider' => 'paystack',
            'tx_ref' => 'HSF-'.uniqid(),
            'amount' => 500,
            'currency' => 'NGN',
            'status' => 'pending',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function configurePlatformSettlementAccount(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.settlement_account'],
            ['value' => [
                'bank_code' => '058',
                'bank_name' => 'GTBank',
                'account_number' => '0000000001',
                'account_name' => 'HotspotFreeRAD Ltd',
                'verified_at' => now()->toDateTimeString(),
            ]]
        );

        Cache::flush();
    }
}
