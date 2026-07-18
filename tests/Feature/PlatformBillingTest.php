<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\PlatformBillingPayment;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            ->assertSee(route('admin.billing.plans.create'), false)
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
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'PLATFORM_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orchestration/direct-orders' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_platform_123',
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
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-orders')
            && $request->hasHeader('Authorization', 'Bearer PLATFORM_TOKEN')
            && $request['amount'] === 35000.0
            && $request['currency'] === 'NGN'
            && $request['metadata']['payment_type'] === 'platform_subscription'
            && $request['metadata']['tenant_name'] === 'Tenant One'
            && $request['metadata']['billing_plan_name'] === 'Growth');

        $payment = PlatformBillingPayment::firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('ord_platform_123', $payment->provider_reference);
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
        ]);
    }
}
