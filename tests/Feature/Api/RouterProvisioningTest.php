<?php

namespace Tests\Feature\Api;

use App\Models\BillingPlan;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reseller model Phase 3 (2026-11-14): zero-touch router provisioning's
 * phone-facing API -- a tenant_admin creates a minimal Router row and
 * receives the exact same bootstrap script super_admin would otherwise
 * view and paste by hand, which the phone then pushes to the router's
 * own factory Wi-Fi over RouterOS 7's REST API (not exercised here --
 * that part only runs on-device; this covers everything server-side).
 */
class RouterProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_create_a_router_and_receives_a_bootstrap_script(): void
    {
        [$tenant, $shop] = $this->fixture();
        $user = $this->tenantAdmin($tenant);
        $token = $user->createToken('Test Device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/router-provisioning', [
                'shop_id' => $shop->id,
                'name' => 'Downtown Router',
            ]);

        $response->assertCreated()
            ->assertJsonPath('router.name', 'Downtown Router')
            ->assertJsonStructure(['router' => ['id', 'name', 'nas_identifier', 'public_token'], 'bootstrap_script']);

        $router = Router::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame('Downtown Router', $router->name);
        $this->assertNotNull($router->wireguard_private_key);
        $this->assertNotNull($router->api_username);
        $this->assertNotNull($router->api_password);

        $script = $response->json('bootstrap_script');
        $this->assertStringContainsString($router->wireguard_private_key, $script);
        $this->assertStringContainsString($router->api_username, $script);
        $this->assertStringContainsString($router->api_password, $script);

        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'router_bootstrap_script_issued',
        ]);
    }

    public function test_tenant_staff_cannot_create_a_router_regardless_of_permissions(): void
    {
        [$tenant, $shop] = $this->fixture();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => ['routers'],
            'is_active' => true,
        ]);
        $token = $staff->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/router-provisioning', ['shop_id' => $shop->id, 'name' => 'Sneaky Router'])
            ->assertForbidden();

        $this->assertDatabaseMissing('routers', ['shop_id' => $shop->id]);
    }

    public function test_tenant_admin_cannot_mark_pushed_or_poll_status_for_another_tenants_router(): void
    {
        [$tenantOne] = $this->fixture('Tenant One', 'Shop One');
        [$tenantTwo, $shopTwo] = $this->fixture('Tenant Two', 'Shop Two');
        $otherRouter = Router::create([
            'shop_id' => $shopTwo->id,
            'name' => 'Not mine',
            'nas_identifier' => 'other-router',
            'wireguard_internal_ip' => '10.8.0.50',
            'shared_secret' => 'radius-secret',
        ]);
        $user = $this->tenantAdmin($tenantOne);
        $token = $user->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/router-provisioning/{$otherRouter->id}/mark-pushed")
            ->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/router-provisioning/{$otherRouter->id}/status")
            ->assertForbidden();

        $this->assertNull($otherRouter->refresh()->bootstrap_pushed_at);
    }

    public function test_status_endpoint_reflects_bootstrap_and_auto_provisioning_progress(): void
    {
        [$tenant, $shop] = $this->fixture();
        $user = $this->tenantAdmin($tenant);
        $token = $user->createToken('Test Device')->plainTextToken;
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Progress Router',
            'nas_identifier' => 'progress-router',
            'wireguard_internal_ip' => '10.8.0.51',
            'shared_secret' => 'radius-secret',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/router-provisioning/{$router->id}/status")
            ->assertOk()
            ->assertJson(['bootstrap_pushed_at' => null, 'auto_provisioned_at' => null, 'is_online' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/router-provisioning/{$router->id}/mark-pushed")
            ->assertOk();

        $this->assertNotNull($router->refresh()->bootstrap_pushed_at);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/router-provisioning/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('auto_provisioned_at', null);

        $router->forceFill(['auto_provisioned_at' => now()])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/router-provisioning/{$router->id}/status")
            ->assertOk()
            ->assertJsonMissing(['auto_provisioned_at' => null]);
    }

    /**
     * @return array{0: Tenant, 1: Shop}
     */
    private function fixture(string $tenantName = 'Demo Tenant', string $shopName = 'Demo Shop'): array
    {
        $tenant = Tenant::create(['company_name' => $tenantName, 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => $shopName]);

        $plan = BillingPlan::create([
            'name' => 'Growth',
            'slug' => 'growth-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
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

        return [$tenant, $shop];
    }

    private function tenantAdmin(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
    }
}
