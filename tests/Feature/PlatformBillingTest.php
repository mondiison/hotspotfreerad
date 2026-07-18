<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertDontSee('Tenant Two');
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
}
