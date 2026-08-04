<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\MikroTikProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroTikProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_routeros_hotspot_script(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.radius.auth_port' => 1812,
            'services.radius.acct_port' => 1813,
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'shop-main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/system identity set name="shop-main-router"', $script);
        $this->assertStringContainsString('endpoint-address=vpn.example.com', $script);
        $this->assertStringContainsString('/ip address add address=10.8.0.10/24 interface=wg-saas', $script);
        $this->assertStringContainsString('/radius add address=10.8.0.1 secret="radius-secret" service=hotspot,ppp', $script);
        $this->assertStringContainsString('authentication-port=1812 accounting-port=1813', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=portal.example.com action=allow', $script);
    }

    public function test_it_generates_a_routeros_pppoe_script(): void
    {
        config([
            'services.radius.server_ip' => '10.8.0.1',
            'services.radius.auth_port' => 1812,
            'services.radius.acct_port' => 1813,
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $router = new Router([
            'nas_identifier' => 'shop-main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generatePppoeScript($router);

        $this->assertStringContainsString('/radius add address=10.8.0.1 secret="radius-secret" service=ppp', $script);
        $this->assertStringContainsString('/ppp aaa set use-radius=yes accounting=yes interim-update=5m', $script);
        $this->assertStringContainsString('Mikrotik-Rate-Limit', $script);
        $this->assertStringNotContainsString('rate-limit=', $script);
        $this->assertStringContainsString('/interface pppoe-server server add interface=bridge1 service-name=mms-radius', $script);
    }

    public function test_it_generates_a_fresh_infrastructure_script_for_starlink_plaza_networks(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.radius.auth_port' => 1812,
            'services.radius.acct_port' => 1813,
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'plaza-core-01',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('Profile: Starlink plaza / high concurrency', $script);
        $this->assertStringContainsString('/interface vlan add interface=$lanBridge name=vlan-hotspot vlan-id=$hotspotVlan', $script);
        $this->assertStringContainsString('/interface vlan add interface=$lanBridge name=vlan-pos vlan-id=$posVlan', $script);
        $this->assertStringContainsString('/ip hotspot add name=mms-hotspot interface=vlan-hotspot', $script);
        $this->assertStringContainsString('/radius add address=10.8.0.1 secret="radius-secret" service=hotspot,ppp', $script);
        $this->assertStringContainsString('/queue type add name=pcq-hotspot-down kind=pcq', $script);
        $this->assertStringContainsString('Realtime voice/video small UDP upload', $script);
        $this->assertStringContainsString('MMS POS = WPA2/WPA3 SSID tagged VLAN 50', $script);
        $this->assertStringContainsString('/system scheduler add name=mms-refresh-bandwidth interval=10m', $script);
    }

    public function test_fresh_infrastructure_script_uses_router_specific_settings(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.radius.auth_port' => 1812,
            'services.radius.acct_port' => 1813,
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'custom-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'small_hotspot',
                'wan1' => 'ether5',
                'trunk_port' => 'sfp-sfpplus1',
                'hotspot_vlan' => 120,
                'hotspot_gateway' => '10.20.0.1/22',
                'hotspot_network' => '10.20.0.0/22',
                'hotspot_pool' => '10.20.0.10-10.20.3.250',
                'download_limit' => '60M',
                'upload_limit' => '8M',
                'enable_pos' => false,
                'enable_pppoe' => false,
                'enable_realtime_qos' => false,
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString(':global wan1 "ether5"', $script);
        $this->assertStringContainsString(':global trunkPort "sfp-sfpplus1"', $script);
        $this->assertStringContainsString(':global hotspotVlan "120"', $script);
        $this->assertStringContainsString(':global hotspotGateway "10.20.0.1/22"', $script);
        $this->assertStringContainsString(':global hotspotPool "10.20.0.10-10.20.3.250"', $script);
        $this->assertStringContainsString('vlan-ids=10,120,30', $script);
        $this->assertStringContainsString('POS VLAN is disabled', $script);
        $this->assertStringContainsString('PPPoE is disabled', $script);
        $this->assertStringContainsString('Realtime QoS and PCQ are disabled', $script);
        $this->assertStringNotContainsString('/queue type add name=pcq-hotspot-down kind=pcq', $script);
    }

    public function test_it_generates_l009_builtin_wifi_hotspot_script(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.radius.auth_port' => 1812,
            'services.radius.acct_port' => 1813,
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'l009-test-router',
            'wireguard_internal_ip' => '10.8.0.30',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'l009_builtin_wifi',
                'hotspot_gateway' => '10.5.50.1/24',
                'hotspot_network' => '10.5.50.0/24',
                'hotspot_pool' => '10.5.50.10-10.5.50.250',
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('Profile: L009 built-in Wi-Fi test router', $script);
        $this->assertStringContainsString(':global wan2 "ether7"', $script);
        $this->assertStringContainsString(':global builtinWifiInterface "wifi1"', $script);
        $this->assertStringContainsString('/interface wifi configuration add name=mms-open-hotspot-cfg mode=ap ssid="MMS Hotspot"', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$builtinWifiInterface pvid=$hotspotVlan', $script);
        $this->assertStringContainsString('untagged=$builtinWifiInterface vlan-ids=$hotspotVlan', $script);
        $this->assertStringContainsString('vlan-ids=10,30', $script);
        $this->assertStringContainsString('POS VLAN is disabled', $script);
        $this->assertStringContainsString('PPPoE is disabled', $script);
    }

    public function test_it_lists_flexible_infrastructure_profiles(): void
    {
        $profiles = app(MikroTikProvisioningService::class)->infrastructureProfiles();

        $this->assertArrayHasKey('starlink_plaza', $profiles);
        $this->assertArrayHasKey('small_hotspot', $profiles);
        $this->assertArrayHasKey('l009_builtin_wifi', $profiles);
        $this->assertArrayHasKey('pppoe_isp', $profiles);
    }
}
