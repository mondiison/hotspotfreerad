<?php

namespace Tests\Feature;

use App\Jobs\SyncRouterWalledGarden;
use App\Jobs\VerifyPlatformBillingWebhook;
use App\Livewire\Admin\BillingPlansManager;
use App\Livewire\Admin\PlatformPaymentSettingsCard;
use App\Models\BillingPlan;
use App\Models\PlatformBillingPayment;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Services\PlatformPaymentSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_super_admin_can_view_and_record_tenant_billing_subscription(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'growth')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Add Plan')
            ->assertSee('Assign Tenant Subscription')
            ->assertSee('Growth')
            ->assertSee('Mondi Internet');

        $this->actingAs($user)
            ->post(route('admin.billing.subscriptions.store'), [
                'tenant_id' => $tenant->id,
                'billing_plan_id' => $plan->id,
                'status' => 'active',
                'current_period_starts_at' => now()->format('Y-m-d H:i:s'),
                'current_period_ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.billing.index'));

        $this->assertDatabaseHas('tenant_billing_subscriptions', [
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => 35000,
            'currency' => 'NGN',
            'provider' => 'flutterwave',
        ]);
    }

    public function test_super_admin_can_create_and_update_billing_plan(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.plans.create'))
            ->assertOk()
            ->assertSee('Add Billing Plan');

        $this->actingAs($user)
            ->post(route('admin.billing.plans.store'), [
                'name' => 'Enterprise Plus',
                'monthly_price' => 125000,
                'currency' => 'ngn',
                'shop_limit' => 20,
                'router_limit' => 50,
                'package_limit' => 200,
                'features' => "Priority support\nDedicated onboarding",
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.billing.index'));

        $plan = BillingPlan::where('slug', 'enterprise-plus')->firstOrFail();
        $this->assertSame(['Priority support', 'Dedicated onboarding'], $plan->features);
        $this->assertSame('NGN', $plan->currency);

        $this->actingAs($user)
            ->put(route('admin.billing.plans.update', $plan), [
                'name' => 'Enterprise Max',
                'slug' => 'enterprise-max',
                'monthly_price' => 150000,
                'currency' => 'USD',
                'features' => 'Priority support',
            ])
            ->assertRedirect(route('admin.billing.index'));

        $this->assertDatabaseHas('billing_plans', [
            'id' => $plan->id,
            'name' => 'Enterprise Max',
            'slug' => 'enterprise-max',
            'monthly_price' => 150000,
            'currency' => 'USD',
            'is_active' => false,
        ]);
    }

    public function test_super_admin_cannot_delete_billing_plan_used_by_subscription(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.billing.plans.destroy', $plan))
            ->assertRedirect(route('admin.billing.index'))
            ->assertSessionHasErrors('billing_plan');

        $this->assertDatabaseHas('billing_plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_livewire_billing_plan_manager_creates_plan_from_modal(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(BillingPlansManager::class)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('name', 'Scale Plus')
            ->set('monthly_price', '85000')
            ->set('currency', 'ngn')
            ->set('shop_limit', '10')
            ->set('router_limit', '20')
            ->set('package_limit', '')
            ->set('features', "Priority support\nAdvanced analytics")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Billing plan created.')
            ->assertSee('Scale Plus');

        $plan = BillingPlan::where('slug', 'scale-plus')->firstOrFail();

        $this->assertSame('NGN', $plan->currency);
        $this->assertSame(['Priority support', 'Advanced analytics'], $plan->features);
        $this->assertNull($plan->package_limit);
    }

    /**
     * Regression/feature test for a 2026-09-23 gap: BillingPlanManagementService::
     * rules()/normalize() already fully supported supports_wallet/wallet_commission_rate
     * (validated, normalized, persisted), and the orphaned full-page plan-form.blade.php
     * (reached only via admin.billing.plans.create/edit, unlinked from navigation --
     * see the other plan-form-route tests in this file) already exposed them too, but
     * the live, linked super-admin UI (this Livewire modal, embedded on admin/billing)
     * never had the fields wired up at all -- a super admin had no way to actually
     * toggle wallet support for a plan from the page they'd normally use.
     */
    public function test_livewire_billing_plan_manager_can_toggle_wallet_support(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(BillingPlansManager::class)
            ->call('create')
            ->set('name', 'Wallet Plan')
            ->set('monthly_price', '50000')
            ->set('supports_wallet', true)
            ->set('wallet_commission_rate', '7.5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Billing plan created.');

        $plan = BillingPlan::where('slug', 'wallet-plan')->firstOrFail();

        $this->assertTrue($plan->supports_wallet);
        $this->assertSame('7.50', $plan->wallet_commission_rate);
    }

    public function test_livewire_billing_plan_manager_edit_prefills_wallet_fields(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $plan = BillingPlan::create([
            'name' => 'Wallet Existing',
            'slug' => 'wallet-existing',
            'monthly_price' => 60000,
            'currency' => 'NGN',
            'supports_wallet' => true,
            'wallet_commission_rate' => 4.25,
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(BillingPlansManager::class)
            ->call('edit', $plan->id)
            ->assertSet('supports_wallet', true)
            ->assertSet('wallet_commission_rate', '4.25');
    }

    public function test_livewire_billing_plan_manager_edits_plan_from_modal(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();

        Livewire::actingAs($user)
            ->test(BillingPlansManager::class)
            ->call('edit', $plan->id)
            ->assertSet('showFormModal', true)
            ->assertSet('name', 'Starter')
            ->set('name', 'Starter Plus')
            ->set('slug', 'starter-plus')
            ->set('monthly_price', '18000')
            ->set('currency', 'usd')
            ->set('features', 'Basic support')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Billing plan updated.');

        $plan->refresh();

        $this->assertSame('Starter Plus', $plan->name);
        $this->assertSame('starter-plus', $plan->slug);
        $this->assertSame('USD', $plan->currency);
        $this->assertFalse($plan->is_active);
    }

    public function test_livewire_billing_plan_manager_deletes_unused_plan_with_confirmation(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $plan = BillingPlan::create([
            'name' => 'Temporary',
            'slug' => 'temporary',
            'monthly_price' => 1000,
            'currency' => 'NGN',
            'features' => [],
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(BillingPlansManager::class)
            ->call('confirmDelete', $plan->id)
            ->assertSet('showDeleteModal', true)
            ->assertSee('Temporary')
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertSee('Billing plan deleted.');

        $this->assertDatabaseMissing('billing_plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_livewire_billing_plan_manager_shows_delete_error_for_used_plan(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(BillingPlansManager::class)
            ->set('deletingPlanId', $plan->id)
            ->call('delete')
            ->assertHasErrors('billing_plan');

        $this->assertDatabaseHas('billing_plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_super_admin_can_update_platform_payment_settings(): void
    {
        config([
            'services.flutterwave.client_id' => null,
            'services.flutterwave.client_secret' => null,
            'services.flutterwave.webhook_secret_hash' => null,
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PlatformPaymentSettingsCard::class)
            ->set('active_gateway', 'flutterwave')
            ->set('gateway_settings.client_id', 'db-platform-client-id')
            ->set('gateway_settings.client_secret', 'db-platform-client-secret')
            ->set('gateway_settings.webhook_secret', 'db-platform-webhook-secret')
            ->set('default_payment_method', 'bank_transfer')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Platform payment settings updated.')
            ->assertSee('Client ID (v4 OPay/transfer) saved')
            ->assertSee('Secret Hash / Webhook Secret saved');

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'payments.platform.gateway.flutterwave',
        ]);

        $service = app(PlatformPaymentSettingsService::class);
        $this->assertSame('db-platform-client-id', $service->clientId());
        $this->assertSame('flutterwave', $service->activeGateway());
        $this->assertSame('db-platform-client-secret', $service->clientSecret());
        $this->assertSame('db-platform-webhook-secret', $service->webhookSecretHash());
        $this->assertSame('bank_transfer', $service->defaultPaymentMethod());
    }

    /**
     * Regression test for the same 2026-09-25 walled-garden gap, from the
     * other direction: switching the platform's own "Active gateway" (not
     * just enabling wallet mode itself) also changes what every wallet-
     * enabled tenant's shops resolve to, and needs the same resync.
     */
    public function test_changing_the_platforms_active_gateway_resyncs_walled_gardens_for_wallet_tenants(): void
    {
        Queue::fake();

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.gateway.monnify'],
            ['value' => [
                'public_key' => Crypt::encryptString('platform-monnify-api-key'),
                'secret_key' => Crypt::encryptString('platform-monnify-secret-key'),
                'contract_code' => Crypt::encryptString('platform-contract-code'),
            ]]
        );
        Cache::flush();

        $tenant = Tenant::create([
            'company_name' => 'Wallet Tenant',
            'owner_email' => 'wallet@example.com',
            'wallet_enabled' => true,
        ]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Wallet Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Router One',
            'nas_identifier' => 'active-gateway-change-router',
            'wireguard_internal_ip' => '10.8.0.211',
            'shared_secret' => 'radius-secret',
        ]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($superAdmin)
            ->test(PlatformPaymentSettingsCard::class)
            ->set('active_gateway', 'monnify')
            ->call('save')
            ->assertHasNoErrors();

        Queue::assertPushed(SyncRouterWalledGarden::class, fn (SyncRouterWalledGarden $job): bool => $job->routerId() === $router->id);
    }

    public function test_tenant_admin_cannot_update_platform_payment_settings(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PlatformPaymentSettingsCard::class)
            ->assertForbidden();
    }

    public function test_platform_checkout_uses_database_payment_settings_before_env(): void
    {
        config([
            'services.flutterwave.client_id' => 'env-platform-client-id',
            'services.flutterwave.client_secret' => 'env-platform-client-secret',
            'services.flutterwave.default_payment_method' => 'opay',
            'services.flutterwave.webhook_secret_hash' => null,
        ]);

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($superAdmin)
            ->test(PlatformPaymentSettingsCard::class)
            ->set('gateway_settings.client_id', 'db-platform-client-id')
            ->set('gateway_settings.client_secret', 'db-platform-client-secret')
            ->set('default_payment_method', 'bank_transfer')
            ->call('save')
            ->assertHasNoErrors();

        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'DATABASE_PLATFORM_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orchestration/direct-charges' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'chg_platform_db_123',
                    'next_action' => [
                        'redirect_url' => [
                            'url' => 'https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/bank-transfer/platform-subscription',
                        ],
                    ],
                ],
            ]),
        ]);

        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'growth')->firstOrFail();
        $tenantAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($tenantAdmin)
            ->post(route('admin.billing.payments.checkout'), [
                'billing_plan_id' => $plan->id,
            ])
            ->assertRedirect('https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/bank-transfer/platform-subscription');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'idp.flutterwave.com')
            && $request['client_id'] === 'db-platform-client-id'
            && $request['client_secret'] === 'db-platform-client-secret');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-charges')
            && data_get($request->data(), 'payment_method.type') === 'bank_transfer');
    }

    public function test_tenant_admin_can_view_own_billing_status(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Tenant Two',
            'owner_email' => 'two@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'trialing',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
        TenantBillingSubscription::create([
            'tenant_id' => $otherTenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Tenant One')
            ->assertSee('Starter')
            ->assertSee('Trialing')
            ->assertSee('Choose Platform Plan')
            ->assertDontSee('Tenant Two');
    }

    public function test_tenant_admin_can_start_platform_subscription_checkout(): void
    {
        $this->configurePlatformFlutterwave();
        config(['services.flutterwave.default_payment_method' => null]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'PLATFORM_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orchestration/direct-charges' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'chg_platform_123',
                    'next_action' => [
                        'redirect_url' => [
                            'url' => 'https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/opay/platform-subscription',
                        ],
                    ],
                ],
            ]),
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'growth')->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.billing.payments.checkout'), [
                'billing_plan_id' => $plan->id,
            ])
            ->assertRedirect('https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/opay/platform-subscription');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'idp.flutterwave.com')
            && $request['client_id'] === 'platform-client-id'
            && $request['client_secret'] === 'platform-client-secret');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-charges')
            && $request->hasHeader('Authorization', 'Bearer PLATFORM_TOKEN')
            && $request['amount'] === 35000.0
            && $request['currency'] === 'NGN'
            && data_get($request->data(), 'payment_method.type') === 'opay'
            && data_get($request->data(), 'customer.address.postal_code') === '100001'
            && $request['meta']['payment_type'] === 'platform_subscription'
            && $request['meta']['tenant_name'] === 'Tenant One'
            && $request['meta']['billing_plan_name'] === 'Growth'
            && str_contains($request['redirect_url'], route('admin.billing.payments.callback'))
            && str_contains($request['redirect_url'], 'tx_ref=PBF-'));

        $payment = PlatformBillingPayment::firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('chg_platform_123', $payment->provider_reference);
    }

    public function test_tenant_admin_can_start_platform_stripe_subscription_checkout(): void
    {
        $this->configurePlatformStripe();
        config(['services.stripe.base_url' => 'https://api.stripe.com/v1']);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => fn ($request) => Http::response([
                'id' => 'cs_platform_123',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/c/pay/cs_platform_123',
                'client_reference_id' => $request['client_reference_id'],
            ]),
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'growth')->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.billing.payments.checkout'), [
                'billing_plan_id' => $plan->id,
            ])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_platform_123');

        $payment = PlatformBillingPayment::firstOrFail();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/checkout/sessions')
            && $request->hasHeader('Authorization', 'Bearer sk_test_platform')
            && $request['client_reference_id'] === $payment->tx_ref
            && $request['customer_email'] === 'one@example.com'
            && $request['line_items'][0]['price_data']['unit_amount'] === 3500000
            && $request['metadata']['payment_type'] === 'platform_subscription'
            && $request['metadata']['tenant_name'] === 'Tenant One'
            && str_contains($request['success_url'], route('admin.billing.payments.callback'))
            && str_contains($request['success_url'], 'session_id={CHECKOUT_SESSION_ID}'));

        $this->assertSame('stripe', $payment->provider);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('cs_platform_123', $payment->provider_reference);
    }

    /**
     * 2026-09-25: platform billing gained a real Monnify adapter (previously
     * Monnify could be picked as "Active gateway" in the settings card but
     * silently fell through to Flutterwave's own credentials at checkout
     * time -- BillingController::checkout()'s dispatch ternary only ever
     * checked for Stripe, everything else defaulted to Flutterwave).
     */
    public function test_tenant_admin_can_start_platform_monnify_subscription_checkout(): void
    {
        $this->configurePlatformMonnify();
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response([
                'responseBody' => ['accessToken' => 'PLATFORM_MONNIFY_TOKEN'],
            ]),
            'sandbox.monnify.com/api/v1/merchant/transactions/init-transaction' => Http::response([
                'responseBody' => [
                    'transactionReference' => 'MNFY|platform|123',
                    'checkoutUrl' => 'https://sandbox.monnify.com/checkout/platform-subscription',
                ],
            ]),
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'growth')->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.billing.payments.checkout'), [
                'billing_plan_id' => $plan->id,
            ])
            ->assertRedirect('https://sandbox.monnify.com/checkout/platform-subscription');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/merchant/transactions/init-transaction')
            && $request->hasHeader('Authorization', 'Bearer PLATFORM_MONNIFY_TOKEN')
            && $request['amount'] === 35000.0
            && $request['currencyCode'] === 'NGN'
            && $request['contractCode'] === 'platform-contract-code'
            && $request['metadata']['payment_type'] === 'platform_subscription'
            && $request['metadata']['tenant_name'] === 'Tenant One');

        $payment = PlatformBillingPayment::firstOrFail();
        $this->assertSame('monnify', $payment->provider);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('MNFY|platform|123', $payment->provider_reference);
    }

    public function test_successful_platform_monnify_callback_activates_billing_subscription(): void
    {
        $this->configurePlatformMonnify();
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'monnify',
            'tx_ref' => 'PBF-MONNIFY-TEST-123',
            'provider_reference' => 'MNFY|platform|callback',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response([
                'responseBody' => ['accessToken' => 'PLATFORM_MONNIFY_TOKEN'],
            ]),
            'sandbox.monnify.com/api/v2/transactions/*' => Http::response([
                'requestSuccessful' => true,
                'responseBody' => [
                    'paymentReference' => $payment->tx_ref,
                    'paymentStatus' => 'PAID',
                    'currency' => 'NGN',
                    'amountPaid' => 15000,
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.payments.callback', [
                'status' => 'successful',
                'tx_ref' => $payment->tx_ref,
                'id' => 'MNFY|platform|callback',
            ]))
            ->assertRedirect(route('admin.billing.index'));

        $this->assertDatabaseHas('platform_billing_payments', [
            'id' => $payment->id,
            'status' => 'successful',
        ]);
        $this->assertDatabaseHas('tenant_billing_subscriptions', [
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'monnify',
        ]);
    }

    public function test_successful_platform_subscription_callback_activates_billing_subscription(): void
    {
        $this->configurePlatformFlutterwave();
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PBF-TEST-123',
            'provider_reference' => 'ord_platform_123',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'PLATFORM_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orders/ord_platform_123' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_platform_123',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 15000,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.payments.callback', [
                'status' => 'succeeded',
                'reference' => $payment->tx_ref,
                'id' => 'ord_platform_123',
            ]))
            ->assertRedirect(route('admin.billing.index'));

        $this->assertDatabaseHas('platform_billing_payments', [
            'id' => $payment->id,
            'status' => 'successful',
            'provider_reference' => 'ord_platform_123',
        ]);
        $this->assertDatabaseHas('tenant_billing_subscriptions', [
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => 15000,
            'currency' => 'NGN',
            'provider_reference' => 'ord_platform_123',
        ]);
    }

    public function test_successful_platform_stripe_callback_activates_billing_subscription(): void
    {
        $this->configurePlatformStripe();
        config(['services.stripe.base_url' => 'https://api.stripe.com/v1']);
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'tx_ref' => 'PBF-STRIPE-TEST-123',
            'provider_reference' => 'cs_platform_callback',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_platform_callback' => Http::response([
                'id' => 'cs_platform_callback',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'client_reference_id' => $payment->tx_ref,
                'amount_total' => 1500000,
                'currency' => 'ngn',
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.payments.callback', [
                'tx_ref' => $payment->tx_ref,
                'session_id' => 'cs_platform_callback',
            ]))
            ->assertRedirect(route('admin.billing.index'));

        $this->assertDatabaseHas('platform_billing_payments', [
            'id' => $payment->id,
            'status' => 'successful',
            'provider_reference' => 'cs_platform_callback',
        ]);
        $this->assertDatabaseHas('tenant_billing_subscriptions', [
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => 15000,
            'currency' => 'NGN',
            'provider_reference' => 'cs_platform_callback',
        ]);
    }

    public function test_admin_can_manually_verify_pending_platform_payment(): void
    {
        $this->configurePlatformFlutterwave();
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PBF-MANUAL-123',
            'provider_reference' => 'ord_manual_123',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'PLATFORM_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orders/ord_manual_123' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_manual_123',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 15000,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('admin.billing.payments.verify', $payment))
            ->assertRedirect(route('admin.billing.index'));

        $this->assertDatabaseHas('platform_billing_payments', [
            'id' => $payment->id,
            'status' => 'successful',
            'provider_reference' => 'ord_manual_123',
        ]);
        $this->assertDatabaseHas('tenant_billing_subscriptions', [
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'provider_reference' => 'ord_manual_123',
        ]);
    }

    public function test_tenant_admin_cannot_verify_another_tenants_platform_payment(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Tenant Two',
            'owner_email' => 'two@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $otherTenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PBF-BLOCKED-123',
            'provider_reference' => 'ord_blocked_123',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.billing.payments.verify', $payment))
            ->assertForbidden();

        $this->assertSame('pending', $payment->refresh()->status);
    }

    public function test_manual_platform_payment_verify_requires_provider_reference(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PBF-NOREF-123',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.billing.payments.verify', $payment))
            ->assertRedirect(route('admin.billing.index'))
            ->assertSessionHasErrors('billing');

        $payment->refresh();

        $this->assertSame('verification_failed', $payment->status);
        $this->assertSame('Provider reference is missing.', data_get($payment->payload, 'manual_verification_error'));
    }

    public function test_successful_platform_monnify_webhook_activates_billing_subscription(): void
    {
        $this->configurePlatformMonnify();
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'monnify',
            'tx_ref' => 'PBF-MONNIFY-WEBHOOK-123',
            'provider_reference' => 'MNFY|platform|webhook',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response([
                'responseBody' => ['accessToken' => 'PLATFORM_MONNIFY_TOKEN'],
            ]),
            'sandbox.monnify.com/api/v2/transactions/*' => Http::response([
                'requestSuccessful' => true,
                'responseBody' => [
                    'paymentReference' => $payment->tx_ref,
                    'paymentStatus' => 'PAID',
                    'currency' => 'NGN',
                    'amountPaid' => 15000,
                ],
            ]),
        ]);

        $payload = [
            'eventType' => 'SUCCESSFUL_TRANSACTION',
            'eventData' => [
                'paymentReference' => $payment->tx_ref,
                'transactionReference' => 'MNFY|platform|webhook',
            ],
        ];
        $signature = hash_hmac('sha512', json_encode($payload), 'platform-monnify-secret-key');

        $this->withHeaders(['monnify-signature' => $signature])
            ->postJson(route('billing.payment.webhook'), $payload)
            ->assertOk()
            ->assertSee('ok');

        $this->assertDatabaseHas('platform_billing_payments', [
            'id' => $payment->id,
            'status' => 'successful',
        ]);
        $this->assertDatabaseHas('tenant_billing_subscriptions', [
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'monnify',
        ]);
    }

    public function test_successful_platform_subscription_webhook_activates_billing_subscription_once(): void
    {
        $this->configurePlatformFlutterwave();
        config(['services.flutterwave.webhook_secret_hash' => 'platform-webhook-secret']);
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PBF-WEBHOOK-123',
            'provider_reference' => 'ord_platform_123',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'PLATFORM_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orders/ord_platform_123' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_platform_123',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 15000,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $payload = [
            'type' => 'charge.completed',
            'data' => [
                'id' => 'ord_platform_123',
                'reference' => $payment->tx_ref,
                'status' => 'succeeded',
            ],
        ];
        $signature = base64_encode(hash_hmac('sha256', json_encode($payload), 'platform-webhook-secret', true));

        $this->withHeaders(['flutterwave-signature' => $signature])
            ->postJson(route('billing.payment.webhook'), $payload)
            ->assertOk()
            ->assertSee('ok');

        $this->withHeaders(['flutterwave-signature' => $signature])
            ->postJson(route('billing.payment.webhook'), $payload)
            ->assertOk()
            ->assertSee('ok');

        $this->assertDatabaseHas('platform_billing_payments', [
            'id' => $payment->id,
            'status' => 'successful',
        ]);
        $this->assertSame(1, TenantBillingSubscription::where('tenant_id', $tenant->id)->count());
    }

    public function test_platform_subscription_webhook_dispatches_billing_verification_job(): void
    {
        Queue::fake();
        $this->configurePlatformFlutterwave();
        config(['services.flutterwave.webhook_secret_hash' => 'platform-webhook-secret']);
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PBF-WEBHOOK-QUEUED',
            'provider_reference' => 'ord_platform_123',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);
        $payload = [
            'type' => 'charge.completed',
            'data' => [
                'id' => 'ord_platform_123',
                'reference' => $payment->tx_ref,
                'status' => 'succeeded',
            ],
        ];
        $signature = base64_encode(hash_hmac('sha256', json_encode($payload), 'platform-webhook-secret', true));

        $this->withHeaders(['flutterwave-signature' => $signature])
            ->postJson(route('billing.payment.webhook'), $payload)
            ->assertOk()
            ->assertSee('ok');

        Queue::assertPushed(VerifyPlatformBillingWebhook::class);
        $this->assertDatabaseHas('platform_billing_payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    public function test_platform_subscription_webhook_rejects_invalid_signature(): void
    {
        config(['services.flutterwave.webhook_secret_hash' => 'platform-webhook-secret']);

        $this->withHeaders(['flutterwave-signature' => 'invalid'])
            ->postJson(route('billing.payment.webhook'), [
                'type' => 'charge.completed',
                'data' => [
                    'id' => 'ord_platform_123',
                    'reference' => 'PBF-WEBHOOK-123',
                    'status' => 'succeeded',
                ],
            ])
            ->assertUnauthorized();
    }

    public function test_tenant_admin_cannot_record_platform_billing_subscription(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.billing.subscriptions.store'), [
                'tenant_id' => $tenant->id,
                'billing_plan_id' => $plan->id,
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tenant_billing_subscriptions', [
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
    }

    public function test_tenant_admin_cannot_manage_billing_plans(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $plan = BillingPlan::where('slug', 'starter')->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.plans.create'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.billing.plans.store'), [
                'name' => 'Blocked',
                'monthly_price' => 1000,
                'currency' => 'NGN',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.billing.plans.edit', $plan))
            ->assertForbidden();
    }

    private function configurePlatformFlutterwave(): void
    {
        config([
            'services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
            'services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com',
            'services.flutterwave.client_id' => 'platform-client-id',
            'services.flutterwave.client_secret' => 'platform-client-secret',
            'services.flutterwave.default_payment_method' => 'opay',
            'services.flutterwave.webhook_secret_hash' => null,
        ]);
    }

    private function configurePlatformStripe(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.general'],
            ['value' => [
                'active_gateway' => 'stripe',
                'default_payment_method' => 'card',
            ]]
        );

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.gateway.stripe'],
            ['value' => [
                'publishable_key' => Crypt::encryptString('pk_test_platform'),
                'secret_key' => Crypt::encryptString('sk_test_platform'),
                'webhook_secret' => Crypt::encryptString('whsec_platform'),
            ]]
        );

        Cache::flush();
    }

    private function configurePlatformMonnify(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.general'],
            ['value' => ['active_gateway' => 'monnify']]
        );

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.gateway.monnify'],
            ['value' => [
                'public_key' => Crypt::encryptString('platform-monnify-api-key'),
                'secret_key' => Crypt::encryptString('platform-monnify-secret-key'),
                'contract_code' => Crypt::encryptString('platform-contract-code'),
                'environment' => Crypt::encryptString('test'),
            ]]
        );

        Cache::flush();
    }
}
