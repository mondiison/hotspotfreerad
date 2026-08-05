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
            'wireguard_private_key' => 'client-private-key',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/system identity set name="shop-main-router"', $script);
        $this->assertStringContainsString('/interface wireguard add name=wg-saas listen-port=13231 mtu=1420 private-key="client-private-key"', $script);
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
            'wireguard_private_key' => 'client-private-key',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generatePppoeScript($router);

        $this->assertStringContainsString('/interface wireguard add name=wg-saas listen-port=13231 mtu=1420 private-key="client-private-key"', $script);
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
        $this->assertStringContainsString(':global piPort "ether3"', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$piPort pvid=$mgmtVlan', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=ether3 vlan-ids=10', $script);
        $this->assertStringContainsString('/ip dhcp-server add name=dhcp-mgmt interface=vlan-mgmt', $script);
        $this->assertStringContainsString('/ip dhcp-client add interface=$wan1 add-default-route=yes use-peer-dns=no disabled=no', $script);
        $this->assertStringContainsString('/ip dhcp-server add name=dhcp-hotspot interface=vlan-hotspot', $script);
        $this->assertStringContainsString('/ip hotspot add name=mms-hotspot interface=vlan-hotspot', $script);
        $this->assertStringContainsString('/radius add address=10.8.0.1 secret="radius-secret" service=hotspot,ppp', $script);
        $this->assertStringContainsString('/queue type add name=pcq-hotspot-down kind=pcq', $script);
        $this->assertStringContainsString('Realtime voice/video small UDP upload', $script);
        $this->assertStringContainsString('MMS POS = WPA2/WPA3 SSID tagged VLAN 50', $script);
        $this->assertStringContainsString('/system scheduler add name=mms-refresh-bandwidth interval=10m', $script);
        $this->assertStringNotContainsString('/interface wifi add name=$staffWifiInterface', $script);
        $this->assertStringNotContainsString('ssid="MMS Staff"', $script);
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
                'pi_port' => 'ether6',
                'hotspot_vlan' => 120,
                'mgmt_gateway' => '192.168.88.1/24',
                'mgmt_network' => '192.168.88.0/24',
                'mgmt_pool' => '192.168.88.10-192.168.88.100',
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
        $this->assertStringContainsString(':global piPort "ether6"', $script);
        $this->assertStringContainsString(':global hotspotVlan "120"', $script);
        $this->assertStringContainsString(':global mgmtGateway "192.168.88.1/24"', $script);
        $this->assertStringContainsString(':global mgmtPool "192.168.88.10-192.168.88.100"', $script);
        $this->assertStringContainsString(':global hotspotGateway "10.20.0.1/22"', $script);
        $this->assertStringContainsString(':global hotspotPool "10.20.0.10-10.20.3.250"', $script);
        $this->assertStringContainsString('untagged=ether6 vlan-ids=10', $script);
        $this->assertStringContainsString('vlan-ids=120,30', $script);
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
        $this->assertStringContainsString(':global staffWifiInterface "wifi-staff"', $script);
        $this->assertStringContainsString(':global posWifiInterface "wifi-pos"', $script);
        $this->assertStringContainsString(':global mgmtWifiInterface "wifi-mgmt"', $script);
        $this->assertStringContainsString('/interface wifi configuration add name=mms-open-hotspot-cfg mode=ap ssid="MMS Hotspot"', $script);
        $this->assertStringContainsString('/interface wifi configuration add name=mms-staff-cfg mode=ap ssid="MMS Staff"', $script);
        $this->assertStringContainsString('/interface wifi configuration add name=mms-pos-cfg mode=ap ssid="MMS POS"', $script);
        $this->assertStringContainsString('/interface wifi configuration add name=mms-mgmt-cfg mode=ap ssid="MMS Mgmt"', $script);
        $this->assertStringContainsString('/interface wifi add name=$staffWifiInterface master-interface=$builtinWifiInterface configuration=mms-staff-cfg disabled=no', $script);
        $this->assertStringContainsString('/interface wifi add name=$posWifiInterface master-interface=$builtinWifiInterface configuration=mms-pos-cfg disabled=no', $script);
        $this->assertStringContainsString('/interface wifi add name=$mgmtWifiInterface master-interface=$builtinWifiInterface configuration=mms-mgmt-cfg disabled=no', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$builtinWifiInterface pvid=$hotspotVlan', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$staffWifiInterface pvid=$staffVlan', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$posWifiInterface pvid=$posVlan', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$mgmtWifiInterface pvid=$mgmtVlan', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$piPort pvid=$mgmtVlan', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=ether3,wifi-mgmt vlan-ids=10', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=wifi1 vlan-ids=20', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=wifi-staff vlan-ids=30', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=wifi-pos vlan-ids=50', $script);
        $this->assertStringContainsString('/ip address add address=$mgmtGateway interface=vlan-mgmt', $script);
        $this->assertStringContainsString('/ip dhcp-server add name=dhcp-hotspot interface=vlan-hotspot', $script);
        $this->assertStringContainsString('Do not attach hotspot DHCP directly to wifi1/ether ports', $script);
        $this->assertStringContainsString('/ip dhcp-server add name=dhcp-pos interface=vlan-pos', $script);
        $this->assertStringContainsString('PPPoE is disabled', $script);
    }

    public function test_it_omits_wireguard_private_key_when_router_has_none(): void
    {
        config([
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $router = new Router([
            'nas_identifier' => 'legacy-router',
            'wireguard_internal_ip' => '10.8.0.40',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/interface wireguard add name=wg-saas listen-port=13231 mtu=1420'."\n", $script);
        $this->assertStringNotContainsString('private-key=', $script);
    }

    public function test_it_lists_flexible_infrastructure_profiles(): void
    {
        $profiles = app(MikroTikProvisioningService::class)->infrastructureProfiles();

        $this->assertArrayHasKey('starlink_plaza', $profiles);
        $this->assertArrayHasKey('small_hotspot', $profiles);
        $this->assertArrayHasKey('l009_builtin_wifi', $profiles);
        $this->assertArrayHasKey('pppoe_isp', $profiles);
    }

    public function test_login_template_uses_public_hotspot_portal_url_when_configured(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8001',
            'services.mikrotik.portal_url' => 'https://mmsradius.com/hotspot/portal',
        ]);

        $template = app(MikroTikProvisioningService::class)->generateLoginTemplate();

        $this->assertStringContainsString('https://mmsradius.com/hotspot/portal?mac=$(mac)&nasid=$(identity)&link-login=$(link-login)&link-orig=$(link-orig)', $template);
        $this->assertStringContainsString("var portal = 'https://mmsradius.com/hotspot/portal'", $template);
        $this->assertStringNotContainsString('http://127.0.0.1:8001/hotspot/portal', $template);
    }
}
