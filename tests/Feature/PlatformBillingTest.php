<?php

namespace Tests\Feature;

use App\Jobs\VerifyPlatformBillingWebhook;
use App\Livewire\Admin\BillingPlansManager;
use App\Models\BillingPlan;
use App\Models\PlatformBillingPayment;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
}
