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
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\TenantManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-10-10: covers the automated per-tenant gateway subaccount
 * settlement pilot -- see CLAUDE.md's "Automated per-tenant gateway
 * subaccount settlement" entry. The load-bearing guarantee under test
 * throughout this file: a tenant that never opts in (subaccount_settlement_gateway
 * left null, every tenant today including the live Monnify one) must
 * resolve/charge EXACTLY as it did before this feature existed.
 */
class SubaccountSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_payment_gateway_follows_platform_default_when_tenant_has_no_override(): void
    {
        $this->configurePlatformActiveGateway('monnify');
        $tenant = $this->walletTenant();
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Live Shop']);

        $this->assertNull($tenant->subaccount_settlement_gateway);
        $this->assertSame('monnify', $shop->paymentGateway());
    }

    public function test_shop_payment_gateway_uses_tenants_override_regardless_of_platform_active_gateway(): void
    {
        $this->configurePlatformActiveGateway('monnify');
        $tenant = $this->walletTenant(['subaccount_settlement_gateway' => 'paystack']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Pilot Shop']);

        $this->assertSame('paystack', $shop->paymentGateway());
    }

    public function test_shop_payment_gateway_is_unaffected_by_other_tenants_override(): void
    {
        $this->configurePlatformActiveGateway('monnify');
        $liveTenant = $this->walletTenant();
        $pilotTenant = $this->walletTenant(['subaccount_settlement_gateway' => 'paystack']);

        $liveShop = Shop::create(['tenant_id' => $liveTenant->id, 'name' => 'Live Shop']);
        $pilotShop = Shop::create(['tenant_id' => $pilotTenant->id, 'name' => 'Pilot Shop']);

        $this->assertSame('monnify', $liveShop->paymentGateway());
        $this->assertSame('paystack', $pilotShop->paymentGateway());
    }

    public function test_credential_resolver_omits_subaccount_code_for_a_tenant_without_the_override(): void
    {
        $this->configurePlatformPaystackCredentials();
        $tenant = $this->walletTenant();
        $payment = $this->walletPayment($tenant);

        $credentials = app(GatewayCredentialResolver::class)->forPayment($payment, 'paystack');

        $this->assertFalse($credentials->has('subaccount_code'));
    }

    public function test_credential_resolver_merges_subaccount_code_for_the_opted_in_tenant_only(): void
    {
        $this->configurePlatformPaystackCredentials();
        $tenant = $this->walletTenant([
            'subaccount_settlement_gateway' => 'paystack',
            'subaccount_code' => 'ACCT_test123',
        ]);
        $payment = $this->walletPayment($tenant);

        $credentials = app(GatewayCredentialResolver::class)->forPayment($payment, 'paystack');

        $this->assertSame('ACCT_test123', $credentials->get('subaccount_code'));
    }

    public function test_credential_resolver_does_not_merge_subaccount_code_for_a_different_gateway(): void
    {
        $this->configurePlatformPaystackCredentials();
        // Opted into Paystack, but this particular charge is being resolved
        // for a different gateway key -- must never leak the code across.
        $tenant = $this->walletTenant([
            'subaccount_settlement_gateway' => 'paystack',
            'subaccount_code' => 'ACCT_test123',
        ]);
        $payment = $this->walletPayment($tenant);

        $credentials = app(GatewayCredentialResolver::class)->forPayment($payment, 'monnify');

        $this->assertFalse($credentials->has('subaccount_code'));
    }

    public function test_paystack_gateway_omits_subaccount_param_when_credentials_have_none(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'ref-1'],
            ]),
        ]);

        app(PaystackGateway::class)->initializeCheckout(
            new GatewayCredentials(['secret_key' => 'sk_test_demo']),
            new ChargeRequest(
                reference: 'ref-1',
                amount: 500,
                currency: 'NGN',
                redirectUrl: 'https://example.test/callback',
                customerEmail: 'customer@example.com',
                customerName: 'Customer',
                description: 'Test',
            )
        );

        Http::assertSent(fn ($request) => ! array_key_exists('subaccount', $request->data()));
    }

    public function test_paystack_gateway_includes_subaccount_param_when_present_in_credentials(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'ref-1'],
            ]),
        ]);

        app(PaystackGateway::class)->initializeCheckout(
            new GatewayCredentials(['secret_key' => 'sk_test_demo', 'subaccount_code' => 'ACCT_test123']),
            new ChargeRequest(
                reference: 'ref-1',
                amount: 500,
                currency: 'NGN',
                redirectUrl: 'https://example.test/callback',
                customerEmail: 'customer@example.com',
                customerName: 'Customer',
                description: 'Test',
            )
        );

        Http::assertSent(fn ($request) => ($request->data()['subaccount'] ?? null) === 'ACCT_test123');
    }

    public function test_paystack_gateway_creates_a_subaccount_with_the_verified_bank_details(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        Http::fake([
            'api.paystack.co/subaccount' => Http::response([
                'status' => true,
                'data' => ['subaccount_code' => 'ACCT_live123'],
            ]),
        ]);

        $result = app(PaystackGateway::class)->createSubaccount(
            new GatewayCredentials(['secret_key' => 'sk_test_platform']),
            'Pilot Tenant Ltd',
            '058',
            '0123456789',
            15.0
        );

        $this->assertSame('ACCT_live123', $result['subaccount_code']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/subaccount'
            && $request->data()['business_name'] === 'Pilot Tenant Ltd'
            && $request->data()['settlement_bank'] === '058'
            && $request->data()['account_number'] === '0123456789'
            && $request->data()['percentage_charge'] === 15.0);
    }

    public function test_set_subaccount_gateway_rejects_an_unimplemented_gateway(): void
    {
        $tenant = $this->walletTenant();
        $actor = $this->superAdmin();

        $this->expectException(ValidationException::class);

        app(TenantManagementService::class)->setSubaccountGateway($tenant, 'flutterwave', $actor);
    }

    public function test_set_subaccount_gateway_clears_a_stale_subaccount_code_on_change(): void
    {
        $tenant = $this->walletTenant([
            'subaccount_settlement_gateway' => 'paystack',
            'subaccount_code' => 'ACCT_old',
            'subaccount_created_at' => now(),
        ]);
        $actor = $this->superAdmin();

        app(TenantManagementService::class)->setSubaccountGateway($tenant, null, $actor);

        $tenant->refresh();
        $this->assertNull($tenant->subaccount_settlement_gateway);
        $this->assertNull($tenant->subaccount_code);
        $this->assertNull($tenant->subaccount_created_at);
    }

    public function test_create_subaccount_requires_a_verified_settlement_account(): void
    {
        $tenant = $this->walletTenant(['subaccount_settlement_gateway' => 'paystack']);
        $actor = $this->superAdmin();

        $this->expectException(ValidationException::class);

        app(TenantManagementService::class)->createSubaccount($tenant, $actor);
    }

    public function test_create_subaccount_stores_the_returned_code(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        $this->configurePlatformPaystackCredentials();
        Http::fake([
            'api.paystack.co/subaccount' => Http::response([
                'status' => true,
                'data' => ['subaccount_code' => 'ACCT_live456'],
            ]),
        ]);

        $tenant = $this->walletTenant([
            'subaccount_settlement_gateway' => 'paystack',
            'commission_rate' => 20,
            'settlement_bank_code' => '058',
            'settlement_bank_name' => 'GTBank',
            'settlement_account_number' => '0123456789',
            'settlement_account_name' => 'Pilot Tenant Ltd',
            'settlement_verified_at' => now(),
        ]);
        $actor = $this->superAdmin();

        app(TenantManagementService::class)->createSubaccount($tenant, $actor);

        $tenant->refresh();
        $this->assertSame('ACCT_live456', $tenant->subaccount_code);
        $this->assertNotNull($tenant->subaccount_created_at);
        $this->assertTrue($tenant->hasSubaccountSettlement());
    }

    public function test_tenants_index_can_save_the_subaccount_gateway_and_create_a_subaccount(): void
    {
        config(['services.paystack.base_url' => 'https://api.paystack.co']);
        $this->configurePlatformPaystackCredentials();
        Http::fake([
            'api.paystack.co/subaccount' => Http::response([
                'status' => true,
                'data' => ['subaccount_code' => 'ACCT_live789'],
            ]),
        ]);

        $tenant = $this->walletTenant([
            'commission_rate' => 20,
            'settlement_bank_code' => '058',
            'settlement_bank_name' => 'GTBank',
            'settlement_account_number' => '0123456789',
            'settlement_account_name' => 'Pilot Tenant Ltd',
            'settlement_verified_at' => now(),
        ]);
        $actor = $this->superAdmin();

        Livewire::actingAs($actor)
            ->test(TenantsIndex::class)
            ->call('edit', $tenant->id)
            ->set('subaccount_settlement_gateway', 'paystack')
            ->call('saveSubaccountGateway')
            ->assertSet('subaccountError', null)
            ->call('createSubaccount')
            ->assertSet('subaccountError', null);

        $tenant->refresh();
        $this->assertSame('paystack', $tenant->subaccount_settlement_gateway);
        $this->assertSame('ACCT_live789', $tenant->subaccount_code);
    }

    public function test_tenants_index_shows_an_error_when_creating_a_subaccount_without_verification(): void
    {
        $tenant = $this->walletTenant();
        $actor = $this->superAdmin();

        Livewire::actingAs($actor)
            ->test(TenantsIndex::class)
            ->call('edit', $tenant->id)
            ->set('subaccount_settlement_gateway', 'paystack')
            ->call('saveSubaccountGateway')
            ->call('createSubaccount')
            ->assertSet('subaccountError', 'This tenant has no verified settlement bank account yet.');
    }

    private function walletTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'company_name' => 'Pilot Tenant '.uniqid(),
            'owner_email' => 'owner-'.uniqid().'@example.com',
            'wallet_enabled' => true,
            'billing_model' => 'commission',
            'commission_rate' => 15,
        ], $overrides));
    }

    private function walletPayment(Tenant $tenant): Payment
    {
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Shop '.uniqid()]);
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

    private function configurePlatformActiveGateway(string $gateway): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.general'],
            ['value' => ['active_gateway' => $gateway]]
        );

        Cache::flush();
    }

    private function configurePlatformPaystackCredentials(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.general'],
            ['value' => ['active_gateway' => 'monnify']]
        );

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.gateway.paystack'],
            ['value' => [
                'public_key' => Crypt::encryptString('pk_test_platform'),
                'secret_key' => Crypt::encryptString('sk_test_platform'),
            ]]
        );

        Cache::flush();
    }
}
