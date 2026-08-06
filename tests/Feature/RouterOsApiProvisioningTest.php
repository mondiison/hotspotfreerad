<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MikroTikProvisioningService;
use App\Services\RouterManagementService;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterOsApiProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function makeShop(): Shop
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);

        return Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);
    }

    public function test_creating_a_router_auto_generates_api_credentials(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'API Router',
            'nas_identifier' => 'api-router',
            'wireguard_internal_ip' => '10.8.0.70',
            'shared_secret' => 'radius-secret',
        ]);

        $this->assertSame(Router::API_USERNAME, $router->api_username);
        $this->assertNotNull($router->api_password);
        $this->assertSame(Router::API_PORT, $router->api_port);
    }

    public function test_api_password_is_hidden_from_serialization(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Hidden API Router',
            'nas_identifier' => 'hidden-api-router',
            'wireguard_internal_ip' => '10.8.0.71',
            'shared_secret' => 'radius-secret',
        ]);

        $this->assertArrayNotHasKey('api_password', $router->toArray());
        $this->assertArrayHasKey('api_username', $router->toArray());
    }

    public function test_regenerating_api_credentials_replaces_the_password(): void
    {
        $shop = $this->makeShop();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Regen API Router',
            'nas_identifier' => 'regen-api-router',
            'wireguard_internal_ip' => '10.8.0.72',
            'shared_secret' => 'radius-secret',
        ]);

        $originalPassword = $router->api_password;

        app(RouterManagementService::class)->regenerateApiCredentials($router, $user);
        $router->refresh();

        $this->assertNotSame($originalPassword, $router->api_password);
    }

    public function test_generated_scripts_embed_the_api_user_provisioning_commands(): void
    {
        config([
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Script API Router',
            'nas_identifier' => 'script-api-router',
            'wireguard_internal_ip' => '10.8.0.73',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/user group add name=mmsradius-api-group', $script);
        $this->assertStringContainsString('/user add name="'.Router::API_USERNAME.'" password="'.$router->api_password.'"', $script);
        $this->assertStringContainsString('/ip service set api disabled=no port=8728 address=10.8.0.0/24', $script);
    }

    public function test_connection_service_reports_a_clear_error_when_router_is_unreachable(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Unreachable Router',
            'nas_identifier' => 'unreachable-router',
            // Reserved documentation-only address, guaranteed to never respond.
            'wireguard_internal_ip' => '192.0.2.1',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(RouterOsConnectionService::class)->testConnection($router);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_test_connection_route_redirects_with_a_status_message(): void
    {
        $shop = $this->makeShop();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Route Test Router',
            'nas_identifier' => 'route-test-router',
            'wireguard_internal_ip' => '192.0.2.2',
            'shared_secret' => 'radius-secret',
        ]);

        $this->actingAs($user)
            ->post(route('admin.routers.test-api-connection', $router))
            ->assertRedirect(route('admin.routers.show', $router));

        $this->assertNotNull(session('status'));
    }

    public function test_bootstrap_script_contains_only_identity_wireguard_and_api_user(): void
    {
        config([
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Bootstrap Router',
            'nas_identifier' => 'bootstrap-router',
            'wireguard_internal_ip' => '10.8.0.74',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateBootstrapScript($router);

        $this->assertStringContainsString('/system identity set name="bootstrap-router"', $script);
        $this->assertStringContainsString('/interface wireguard peers add interface=wg-saas', $script);
        $this->assertStringContainsString('/ip address add address=10.8.0.74/24 interface=wg-saas', $script);
        $this->assertStringContainsString('/user add name="'.Router::API_USERNAME.'"', $script);

        $this->assertStringNotContainsString('/radius add', $script);
        $this->assertStringNotContainsString('/ip hotspot', $script);
        $this->assertStringNotContainsString('/ppp', $script);
    }

    public function test_provision_hotspot_reports_a_clear_error_when_router_is_unreachable(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Unreachable Hotspot Router',
            'nas_identifier' => 'unreachable-hotspot-router',
            'wireguard_internal_ip' => '192.0.2.3',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(RouterOsConnectionService::class)->provisionHotspot($router);

        $this->assertFalse($result['success']);
        $this->assertCount(4, $result['steps']);
        $this->assertFalse($result['steps'][0]['success']);
        $this->assertNotEmpty($result['steps'][0]['error']);
        $this->assertSame('Point hotspot server at "saas-prof"', $result['steps'][3]['label']);
        $this->assertFalse($result['steps'][3]['success']);
    }

    public function test_provision_pppoe_reports_a_clear_error_when_router_is_unreachable(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Unreachable PPPoE Router',
            'nas_identifier' => 'unreachable-pppoe-router',
            'wireguard_internal_ip' => '192.0.2.4',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(RouterOsConnectionService::class)->provisionPppoe($router);

        $this->assertFalse($result['success']);
        $this->assertCount(4, $result['steps']);
        $this->assertFalse($result['steps'][0]['success']);
    }

    public function test_provision_routes_require_api_credentials_and_redirect_with_a_status_message(): void
    {
        $shop = $this->makeShop();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Provision Route Router',
            'nas_identifier' => 'provision-route-router',
            'wireguard_internal_ip' => '192.0.2.5',
            'shared_secret' => 'radius-secret',
        ]);

        $this->actingAs($user)
            ->post(route('admin.routers.provision-hotspot', $router))
            ->assertRedirect(route('admin.routers.show', $router));

        $this->assertNotNull(session('status'));

        $this->actingAs($user)
            ->post(route('admin.routers.provision-pppoe', $router))
            ->assertRedirect(route('admin.routers.show', $router));

        $this->assertNotNull(session('status'));
    }
}
