<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPlanLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_needs_active_platform_subscription_to_create_shop(): void
    {
        $tenant = $this->tenant();
        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->post(route('admin.shops.store'), [
                'tenant_id' => $tenant->id,
                'name' => 'Second Shop',
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('billing');

        $this->assertDatabaseMissing('shops', [
            'name' => 'Second Shop',
        ]);
    }

    public function test_shop_limit_blocks_tenant_admin_from_adding_more_shops(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, ['shop_limit' => 1]);
        Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Existing Shop',
        ]);
        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->post(route('admin.shops.store'), [
                'tenant_id' => $tenant->id,
                'name' => 'Blocked Shop',
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('billing');

        $this->assertDatabaseMissing('shops', [
            'name' => 'Blocked Shop',
        ]);
    }

    public function test_router_limit_blocks_tenant_admin_from_adding_more_routers(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, ['router_limit' => 1]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
        ]);
        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Existing Router',
            'nas_identifier' => 'existing-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);
        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->post(route('admin.routers.store'), [
                'shop_id' => $shop->id,
                'name' => 'Blocked Router',
                'nas_identifier' => 'blocked-router',
                'wireguard_internal_ip' => '10.8.0.11',
                'shared_secret' => 'radius-secret',
            ])
            ->assertSessionHasErrors('billing');

        $this->assertDatabaseMissing('routers', [
            'nas_identifier' => 'blocked-router',
        ]);
    }

    public function test_package_limit_blocks_tenant_admin_from_adding_more_packages(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, ['package_limit' => 1]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
        ]);
        Package::create([
            'shop_id' => $shop->id,
            'name' => 'Existing Plan',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->post(route('admin.packages.store'), [
                'shop_id' => $shop->id,
                'name' => 'Blocked Plan',
                'price' => 500,
                'currency' => 'NGN',
                'limit_uptime_seconds' => 3600,
                'speed_limit_profile' => '5M/5M',
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('billing');

        $this->assertDatabaseMissing('packages', [
            'name' => 'Blocked Plan',
        ]);
    }

    public function test_create_forms_show_tenant_billing_allowance(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, [
            'shop_limit' => 2,
            'router_limit' => 3,
            'package_limit' => 4,
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
        ]);
        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);
        Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily Plan',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->get(route('admin.shops.create'))
            ->assertOk()
            ->assertSee('Platform allowance')
            ->assertSee('1 locations')
            ->assertSee('2')
            ->assertSee('You can add this item under the current platform plan.');

        $this->actingAs($user)
            ->get(route('admin.routers.create'))
            ->assertOk()
            ->assertSee('Platform allowance')
            ->assertSee('1 routers')
            ->assertSee('3');

        $this->actingAs($user)
            ->get(route('admin.packages.create'))
            ->assertOk()
            ->assertSee('Platform allowance')
            ->assertSee('1 packages')
            ->assertSee('4');
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => fake()->unique()->company(),
            'owner_email' => fake()->unique()->safeEmail(),
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

    private function subscribeTenant(Tenant $tenant, array $limits = []): TenantBillingSubscription
    {
        $plan = BillingPlan::create([
            'name' => 'Limited',
            'slug' => 'limited-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
            'shop_limit' => $limits['shop_limit'] ?? null,
            'router_limit' => $limits['router_limit'] ?? null,
            'package_limit' => $limits['package_limit'] ?? null,
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
