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
        $this->assertStringContainsString('policy=read,write,api,test,sensitive,ftp,', $script);
        $this->assertStringNotContainsString('!test', $script);
        $this->assertStringNotContainsString('!sensitive', $script);
        $this->assertStringNotContainsString('!ftp', $script);
        $this->assertStringContainsString('/user add name="'.Router::API_USERNAME.'" password="'.$router->api_password.'"', $script);
        $this->assertStringContainsString('/ip service set api disabled=no port=8728 address=10.8.0.0/24', $script);
    }

    public function test_api_group_policy_omits_flags_routeros_7_rejects_and_denies_rest_api(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Policy Router',
            'nas_identifier' => 'policy-router',
            'wireguard_internal_ip' => '10.8.0.80',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        // Confirmed invalid on a real RouterOS 7.18.2 router ("input does not match
        // any value of policy") -- the whole /user group add line failed silently,
        // so the API user was never actually created.
        $this->assertStringNotContainsString('dude', $script);
        $this->assertStringNotContainsString('tikapp', $script);
        $this->assertStringContainsString('!rest-api', $script);
    }

    public function test_api_user_provisioning_is_idempotent_for_regenerated_credentials(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Idempotent Router',
            'nas_identifier' => 'idempotent-router',
            'wireguard_internal_ip' => '10.8.0.81',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        // Update-in-place if the group/user already exist, rather than the plain
        // /user group add / /user add erroring on a duplicate name and leaving a
        // regenerated password stuck out of sync with the router.
        $this->assertStringContainsString(':if ([:len [/user group find name=mmsradius-api-group]] = 0) do={', $script);
        $this->assertStringContainsString('/user group set [find name=mmsradius-api-group]', $script);
        $this->assertStringContainsString(':if ([:len [/user find name="'.Router::API_USERNAME.'"]] = 0) do={', $script);
        $this->assertStringContainsString('/user set [find name="'.Router::API_USERNAME.'"] password="'.$router->api_password.'"', $script);
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

    public function test_connection_service_falls_back_to_zerotier_and_still_reports_a_clear_error_when_both_are_unreachable(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Unreachable Dual Tunnel Router',
            'nas_identifier' => 'unreachable-dual-tunnel-router',
            // Reserved documentation-only addresses, guaranteed to never respond.
            'wireguard_internal_ip' => '192.0.2.9',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'wireguard_zerotier',
            'zerotier_ip' => '192.0.2.10',
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

        // API service address restriction sync, RADIUS client, hotspot profile,
        // portal walled-garden entry, one walled-garden entry per host in the
        // shop's active gateway's PaymentGatewayCatalog list (flutterwave by
        // default: *.flutterwave.com, *.ravepay.co), the Cloudflare
        // walled-garden entry, the hotspot login page push, then the final
        // "point hotspot server" step.
        $this->assertFalse($result['success']);
        $this->assertCount(9, $result['steps']);
        $this->assertFalse($result['steps'][0]['success']);
        $this->assertNotEmpty($result['steps'][0]['error']);
        $labels = array_column($result['steps'], 'label');
        $this->assertContains('Push hotspot login page', $labels);
        $lastStep = $result['steps'][count($result['steps']) - 1];
        $this->assertSame('Point hotspot server at "saas-prof"', $lastStep['label']);
        $this->assertFalse($lastStep['success']);
    }

    public function test_provision_hotspot_walled_gardens_the_shops_active_payment_gateway(): void
    {
        $shop = $this->makeShop();
        $shop->update(['payment_gateway' => 'squad']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Squad Router',
            'nas_identifier' => 'squad-router',
            'wireguard_internal_ip' => '192.0.2.5',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(RouterOsConnectionService::class)->provisionHotspot($router);

        $labels = array_column($result['steps'], 'label');

        $this->assertContains('Add walled-garden entry (*.squadco.com)', $labels);
        $this->assertContains('Add walled-garden entry (*.cloudflare.com)', $labels);
        $this->assertNotContains('Add walled-garden entry (*.flutterwave.com)', $labels);
    }

    public function test_push_hotspot_login_page_reports_a_clear_error_when_router_is_unreachable(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Unreachable Login Page Router',
            'nas_identifier' => 'unreachable-login-page-router',
            'wireguard_internal_ip' => '192.0.2.6',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(RouterOsConnectionService::class)->pushHotspotLoginPage($router);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['steps']);
        $this->assertSame('Push hotspot login page', $result['steps'][0]['label']);
        $this->assertFalse($result['steps'][0]['success']);
        $this->assertNotEmpty($result['steps'][0]['error']);
    }

    public function test_push_hotspot_login_page_accepts_a_custom_directory(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Custom Directory Login Page Router',
            'nas_identifier' => 'custom-directory-login-page-router',
            'wireguard_internal_ip' => '192.0.2.7',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(RouterOsConnectionService::class)->pushHotspotLoginPage($router, 'hotspot1');

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['steps']);
        $this->assertSame('Push hotspot login page', $result['steps'][0]['label']);
    }

    public function test_list_hotspot_directories_reports_a_clear_error_when_router_is_unreachable(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Unreachable Directory Listing Router',
            'nas_identifier' => 'unreachable-directory-listing-router',
            'wireguard_internal_ip' => '192.0.2.8',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(RouterOsConnectionService::class)->listHotspotDirectories($router);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_login_page_url_uses_the_same_host_as_the_portal_url(): void
    {
        config(['services.mikrotik.portal_url' => 'https://mmsradius.example.com/hotspot/portal']);

        $url = app(MikroTikProvisioningService::class)->loginPageUrl();

        $this->assertSame('https://mmsradius.example.com/hotspot/login-page', $url);
    }

    public function test_hotspot_login_page_html_embeds_the_portal_url_and_mikrotik_placeholders(): void
    {
        config(['services.mikrotik.portal_url' => 'https://mmsradius.example.com/hotspot/portal']);

        $html = app(MikroTikProvisioningService::class)->hotspotLoginPageHtml();

        $this->assertStringContainsString("var portal = 'https://mmsradius.example.com/hotspot/portal'", $html);
        $this->assertStringContainsString('$(mac)', $html);
        $this->assertStringContainsString('$(identity)', $html);
        $this->assertStringContainsString('$(link-login)', $html);
        $this->assertStringContainsString('$(link-orig)', $html);
        $this->assertStringContainsString('window.location.replace(portal)', $html);
    }

    public function test_login_page_route_serves_the_stub_html(): void
    {
        config(['services.mikrotik.portal_url' => 'https://mmsradius.example.com/hotspot/portal']);

        $response = $this->get(route('hotspot.login-page'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('https://mmsradius.example.com/hotspot/portal', false);
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
        $this->assertCount(5, $result['steps']);
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
