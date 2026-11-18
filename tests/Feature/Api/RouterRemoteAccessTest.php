<?php

namespace Tests\Feature\Api;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile "remote access" feature (2026-11-18): hands tenant_admin the
 * router's tunnel IP plus a dedicated, read-only RouterOS login for their
 * own Winbox/SSH/WebFig client, and a narrow SSID-change action -- the
 * one genuinely safe write this feature offers, pushed server-side over
 * the existing mmsradius-api connection rather than the viewer account.
 */
class RouterRemoteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_list_their_own_routers(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo Tenant', 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Demo Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.70',
            'shared_secret' => 'radius-secret',
        ]);
        $otherTenant = Tenant::create(['company_name' => 'Other Tenant', 'owner_email' => fake()->unique()->safeEmail()]);
        $otherShop = Shop::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Shop']);
        Router::create([
            'shop_id' => $otherShop->id,
            'name' => 'Not mine',
            'nas_identifier' => 'other-router',
            'wireguard_internal_ip' => '10.8.0.71',
            'shared_secret' => 'radius-secret',
        ]);

        $user = $this->tenantAdmin($tenant);
        $token = $user->createToken('Test Device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/routers');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($router->id, $response->json('data.0.id'));
        $this->assertSame('Demo Shop', $response->json('data.0.shop_name'));
        // The listing never exposes any secret field.
        $response->assertJsonMissingPath('data.0.api_password');
        $response->assertJsonMissingPath('data.0.viewer_password');
    }

    public function test_tenant_admin_can_reveal_remote_access_credentials_generating_them_on_first_use(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo Tenant', 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Demo Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.72',
            'shared_secret' => 'radius-secret',
        ]);
        // Simulates a router created before this feature existed -- the
        // model's creating() hook already backfills this on every new
        // Router::create() call, so this test needs to force the
        // pre-feature state to actually exercise ensureViewerCredentials().
        $router->forceFill(['viewer_username' => null, 'viewer_password' => null])->save();

        $user = $this->tenantAdmin($tenant);
        $token = $user->createToken('Test Device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/routers/{$router->id}/credentials");

        $response->assertOk()
            ->assertJsonPath('wireguard_ip', '10.8.0.72')
            ->assertJsonPath('winbox_port', Router::VIEWER_WINBOX_PORT)
            ->assertJsonPath('ssh_port', Router::VIEWER_SSH_PORT)
            ->assertJsonPath('username', Router::VIEWER_USERNAME);

        $router->refresh();
        $this->assertNotNull($router->viewer_password);
        $this->assertSame($router->viewer_password, $response->json('password'));

        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'router_remote_credentials_viewed',
        ]);
    }

    public function test_revealing_credentials_twice_does_not_change_an_already_generated_password(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo Tenant', 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Demo Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.73',
            'shared_secret' => 'radius-secret',
        ]);
        $user = $this->tenantAdmin($tenant);
        $token = $user->createToken('Test Device')->plainTextToken;

        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/routers/{$router->id}/credentials")->json('password');
        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/routers/{$router->id}/credentials")->json('password');

        $this->assertSame($first, $second);
    }

    public function test_tenant_staff_cannot_reveal_credentials_regardless_of_permissions(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo Tenant', 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Demo Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.74',
            'shared_secret' => 'radius-secret',
        ]);
        $router->forceFill(['viewer_username' => null, 'viewer_password' => null])->save();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => ['routers'],
            'is_active' => true,
        ]);
        $token = $staff->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/routers/{$router->id}/credentials")
            ->assertForbidden();

        $this->assertNull($router->refresh()->viewer_password);
    }

    public function test_tenant_admin_cannot_reveal_credentials_for_another_tenants_router(): void
    {
        $tenantOne = Tenant::create(['company_name' => 'Tenant One', 'owner_email' => fake()->unique()->safeEmail()]);
        $tenantTwo = Tenant::create(['company_name' => 'Tenant Two', 'owner_email' => fake()->unique()->safeEmail()]);
        $shopTwo = Shop::create(['tenant_id' => $tenantTwo->id, 'name' => 'Shop Two']);
        $otherRouter = Router::create([
            'shop_id' => $shopTwo->id,
            'name' => 'Not mine',
            'nas_identifier' => 'other-router',
            'wireguard_internal_ip' => '10.8.0.75',
            'shared_secret' => 'radius-secret',
        ]);
        $user = $this->tenantAdmin($tenantOne);
        $token = $user->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/routers/{$otherRouter->id}/credentials")
            ->assertForbidden();
    }

    public function test_updating_ssid_reports_a_clear_error_when_router_is_unreachable(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo Tenant', 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Demo Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.76',
            'shared_secret' => 'radius-secret',
        ]);
        $user = $this->tenantAdmin($tenant);
        $token = $user->createToken('Test Device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/routers/{$router->id}/ssid", ['ssid' => 'New Shop Wi-Fi']);

        $response->assertOk()->assertJsonPath('success', false);
        $this->assertNotNull($response->json('error'));
    }

    public function test_updating_ssid_requires_a_value(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo Tenant', 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Demo Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.77',
            'shared_secret' => 'radius-secret',
        ]);
        $user = $this->tenantAdmin($tenant);
        $token = $user->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/routers/{$router->id}/ssid", ['ssid' => ''])
            ->assertStatus(422);
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
