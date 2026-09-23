<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TrustedWifiDevice;
use App\Services\MikroTikProvisioningService;
use App\Services\RadiusProvisioningService;
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
        $this->assertStringContainsString('# WireGuard endpoint: vpn.example.com:13231 (default public endpoint)', $script);
        $this->assertStringContainsString('endpoint-address=vpn.example.com', $script);
        $this->assertStringContainsString('/ip address add address=10.8.0.10/24 interface=wg-saas', $script);
        $this->assertStringContainsString('/radius add address=10.8.0.1 secret="radius-secret" service=hotspot,ppp', $script);
        $this->assertStringContainsString('authentication-port=1812 accounting-port=1813', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=portal.example.com action=allow', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=wa.me action=allow', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*.wa.me action=allow', $script);
        $this->assertStringContainsString('/ip hotspot set [find] profile=saas-prof', $script);
        $this->assertStringContainsString('login-by=http-pap,http-chap,cookie,mac-cookie', $script);
        $this->assertStringContainsString('html-directory=flash/hotspot', $script);
    }

    /**
     * Confirmed live 2026-09-22: this router's saved directory (set via the
     * "Hotspot Login Page" Live-tab section after RouterOS reported its real
     * login.html location as something other than flash/hotspot) kept
     * getting silently reverted back to "flash/hotspot" on every script
     * regeneration/provision, since neither generateScript() nor
     * generateFreshInfrastructureScript() nor provisionHotspot() ever read
     * hotspot_login_directory for the profile's own html-directory property
     * -- only pushHotspotLoginPage()'s file destination respected it. A
     * profile whose html-directory doesn't match where the file actually
     * lives means nobody can log in, since RouterOS's HTTP server looks in
     * the directory the active profile names.
     */
    public function test_hotspot_scripts_use_the_routers_saved_login_directory(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'custom-directory-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
            'hotspot_login_directory' => 'hotspot',
        ]);

        $hotspotScript = app(MikroTikProvisioningService::class)->generateScript($router);
        $freshInfraScript = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('html-directory=hotspot ', $hotspotScript);
        $this->assertStringNotContainsString('html-directory=flash/hotspot', $hotspotScript);
        $this->assertStringContainsString('html-directory=hotspot ', $freshInfraScript);
        $this->assertStringNotContainsString('html-directory=flash/hotspot', $freshInfraScript);
    }

    public function test_scripts_use_the_routers_endpoint_override_when_set(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Co-Located Router',
            'nas_identifier' => 'co-located-router',
            'wireguard_internal_ip' => '10.8.0.40',
            'shared_secret' => 'radius-secret',
            'wireguard_endpoint_override_host' => '192.168.10.250',
            'wireguard_endpoint_override_port' => 13231,
        ]);

        $service = app(MikroTikProvisioningService::class);

        foreach ([
            $service->generateBootstrapScript($router),
            $service->generateScript($router),
            $service->generatePppoeScript($router),
        ] as $script) {
            $this->assertStringContainsString('endpoint-address=192.168.10.250', $script);
            $this->assertStringContainsString('router local override', $script);
            $this->assertStringNotContainsString('endpoint-address=vpn.example.com', $script);
        }
    }

    public function test_scripts_fall_back_to_the_default_endpoint_when_no_override_is_set(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Remote Router',
            'nas_identifier' => 'remote-router',
            'wireguard_internal_ip' => '10.8.0.41',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('endpoint-address=vpn.example.com', $script);
        $this->assertStringContainsString('endpoint-port=13231', $script);
    }

    public function test_hotspot_script_walled_garden_matches_the_shops_active_gateway(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop', 'payment_gateway' => 'stripe']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Stripe Router',
            'nas_identifier' => 'stripe-router',
            'wireguard_internal_ip' => '10.8.0.32',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=portal.example.com action=allow', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*.stripe.com action=allow', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*.cloudflare.com action=allow', $script);
        $this->assertStringNotContainsString('*.flutterwave.com', $script);
        $this->assertStringNotContainsString('*.paystack', $script);
    }

    public function test_hotspot_script_has_no_gateway_walled_garden_entries_for_manual_bank_transfer(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop', 'payment_gateway' => 'manual_bank']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Manual Bank Router',
            'nas_identifier' => 'manual-bank-router',
            'wireguard_internal_ip' => '10.8.0.33',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=portal.example.com action=allow', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*.cloudflare.com action=allow', $script);
        $this->assertStringNotContainsString('*.flutterwave.com', $script);
        $this->assertStringNotContainsString('*.stripe.com', $script);
    }

    public function test_fresh_infrastructure_script_walled_garden_matches_the_shops_active_gateway(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop', 'payment_gateway' => 'paystack']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Paystack Router',
            'nas_identifier' => 'paystack-router',
            'wireguard_internal_ip' => '10.8.0.34',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*.paystack.com action=allow', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*.paystack.co action=allow', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*.cloudflare.com action=allow', $script);
        $this->assertStringNotContainsString('*.flutterwave.com', $script);
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
        $this->assertStringContainsString('/interface pppoe-server server add interface=vlan-pppoe service-name=mms-radius', $script);
    }

    public function test_it_generates_a_routeros_pos_script(): void
    {
        config([
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

        $script = app(MikroTikProvisioningService::class)->generatePosScript($router);

        $this->assertStringContainsString('/interface wireguard add name=wg-saas listen-port=13231 mtu=1420 private-key="client-private-key"', $script);
        $this->assertStringContainsString('/interface vlan add interface=bridge-lan name=vlan-pos vlan-id=50', $script);
        $this->assertStringContainsString('/ip address add address=192.168.50.1/24 interface=vlan-pos', $script);
        $this->assertStringContainsString('/ip pool add name=pool-pos ranges=192.168.50.10-192.168.50.250', $script);
        $this->assertStringContainsString('/ip dhcp-server add name=dhcp-pos interface=vlan-pos address-pool=pool-pos', $script);
        $this->assertStringContainsString('/ip hotspot profile add name=mms-pos-profile use-radius=yes login-by=mac mac-auth-password="'.RadiusProvisioningService::POS_MAC_AUTH_PASSWORD.'" radius-accounting=yes', $script);
        $this->assertStringContainsString('/ip hotspot add name=mms-pos interface=vlan-pos address-pool=pool-pos profile=mms-pos-profile disabled=no', $script);
        $this->assertStringContainsString('place-before=[find action=drop in-interface-list=!WAN]', $script);
        // Deliberately no /radius add line -- POS shares the RADIUS client the
        // Hotspot Script (or Bootstrap/Fresh Infrastructure scripts) already adds
        // for the "hotspot" service, adding a second one here would just be a
        // genuine duplicate RouterOS never dedupes on its own.
        $this->assertStringNotContainsString('/radius add', $script);
    }

    public function test_pos_script_includes_builtin_wifi_and_extra_ports_when_configured(): void
    {
        config([
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $router = new Router([
            'nas_identifier' => 'bebeji-router01',
            'wireguard_internal_ip' => '10.8.0.11',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'starlink_plaza',
                'trunk_port' => 'ether4',
                'builtin_wifi_interface' => 'wifi1',
                'pos_ssid' => 'MMS POS',
                'pos_wifi_password' => 'MmsPos2026!',
                'extra_pos_ports' => 'ether7',
                'enable_builtin_wifi' => true,
                'enable_pos' => true,
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generatePosScript($router);

        $this->assertStringContainsString('/interface wifi security add name=mms-pos-sec authentication-types=wpa2-psk,wpa3-psk passphrase="MmsPos2026!"', $script);
        $this->assertStringContainsString('/interface wifi configuration add name=mms-pos-cfg mode=ap ssid="MMS POS" security=mms-pos-sec country=Nigeria', $script);
        $this->assertStringContainsString('/interface wifi add name=wifi-pos master-interface=wifi1 configuration=mms-pos-cfg disabled=no', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-lan interface=wifi-pos pvid=50 comment="Virtual POS Wi-Fi for terminal testing"', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-lan interface=ether7 pvid=50 comment="Extra POS access port"', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether4 untagged=wifi-pos,ether7 vlan-ids=50', $script);
    }

    public function test_pos_script_omits_wifi_lines_without_builtin_wifi(): void
    {
        $router = new Router([
            'nas_identifier' => 'wired-router',
            'wireguard_internal_ip' => '10.8.0.12',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'enable_builtin_wifi' => false,
                'enable_pos' => true,
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generatePosScript($router);

        $this->assertStringNotContainsString('mms-pos-sec', $script);
        $this->assertStringNotContainsString('mms-pos-cfg', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,'.'ether2 vlan-ids=50', $script);
    }

    public function test_staff_script_creates_staff_and_management_wifi_with_ports_and_access_list(): void
    {
        config([
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop', 'location_city' => 'Lagos']);

        TrustedWifiDevice::create([
            'shop_id' => $shop->id,
            'network' => TrustedWifiDevice::NETWORK_STAFF,
            'device_name' => "Manager's Laptop",
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'is_active' => true,
        ]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'bebeji-router01',
            'wireguard_internal_ip' => '10.8.0.11',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'starlink_plaza',
                'trunk_port' => 'ether4',
                'builtin_wifi_interface' => 'wifi1',
                'staff_wifi_password' => 'MmsStaff2026!',
                'mgmt_wifi_password' => 'MmsMgmt2026!',
                'extra_staff_ports' => 'ether6',
                'extra_mgmt_ports' => 'ether2',
                'enable_builtin_wifi' => true,
                'enable_staff' => true,
                'enable_mgmt_wifi' => true,
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateStaffScript($router);

        $this->assertStringContainsString('/interface vlan add interface=bridge-lan name=vlan-staff vlan-id=30', $script);
        $this->assertStringContainsString('/interface wifi security add name=mms-staff-sec authentication-types=wpa2-psk,wpa3-psk passphrase="MmsStaff2026!"', $script);
        $this->assertStringContainsString('/interface wifi configuration add name=mms-staff-cfg mode=ap ssid="MMS Staff" security=mms-staff-sec country=Nigeria', $script);
        $this->assertStringContainsString('/interface wifi add name=wifi-staff master-interface=wifi1 configuration=mms-staff-cfg disabled=no', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-lan interface=ether6 pvid=30 comment="Extra staff access port"', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether4 untagged=wifi-staff,ether6 vlan-ids=30', $script);
        $this->assertStringContainsString('/ip address add address=192.168.30.1/24 interface=vlan-staff', $script);

        $this->assertStringContainsString('/interface wifi security add name=mms-mgmt-sec authentication-types=wpa2-psk,wpa3-psk passphrase="MmsMgmt2026!"', $script);
        $this->assertStringContainsString('/interface wifi add name=wifi-mgmt master-interface=wifi1 configuration=mms-mgmt-cfg disabled=no', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-lan interface=ether2 pvid=10 comment="Extra management access port"', $script);
        $this->assertStringNotContainsString('/interface vlan add interface=bridge-lan name=vlan-mgmt', $script);

        $this->assertStringContainsString('/interface wifi access-list remove [find interface=wifi-staff]', $script);
        $this->assertStringContainsString('/interface wifi access-list remove [find interface=wifi-mgmt]', $script);
        $this->assertStringContainsString('/interface wifi access-list add interface=wifi-staff mac-address=AA:BB:CC:DD:EE:FF action=accept comment="Manager\'s Laptop"', $script);
        $this->assertStringContainsString('/interface wifi access-list add interface=wifi-staff action=reject comment="Default-deny: only registered MMS Staff devices may join"', $script);
        $this->assertStringContainsString('No trusted MMS Mgmt devices registered', $script);
        $this->assertStringNotContainsString('/interface wifi access-list add interface=wifi-mgmt action=reject', $script);
    }

    public function test_staff_script_explains_itself_without_builtin_wifi(): void
    {
        $router = new Router([
            'nas_identifier' => 'wired-router',
            'wireguard_internal_ip' => '10.8.0.12',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'enable_builtin_wifi' => false,
                'enable_staff' => true,
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateStaffScript($router);

        $this->assertStringContainsString('does not use MikroTik\'s built-in Wi-Fi', $script);
        $this->assertStringContainsString('docs/staff-wifi-access.md', $script);
        $this->assertStringNotContainsString('/interface vlan add', $script);
        $this->assertStringNotContainsString('/interface wifi security add', $script);
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
        $this->assertStringContainsString('WireGuard endpoint: vpn.example.com:13231 (default public endpoint)', $script);
        $this->assertStringContainsString('/interface vlan add interface=$lanBridge name=vlan-hotspot vlan-id=$hotspotVlan', $script);
        $this->assertStringContainsString('/interface vlan add interface=$lanBridge name=vlan-pos vlan-id=$posVlan', $script);
        $this->assertStringContainsString(':global piPort "ether3"', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=$piPort pvid=$mgmtVlan', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=ether3 vlan-ids=10', $script);
        $this->assertStringContainsString('/interface list add name=MGMT-ACCESS', $script);
        $this->assertStringContainsString('/interface list member add list=MGMT-ACCESS interface=$piPort', $script);
        $this->assertStringContainsString('/tool mac-server set allowed-interface-list=MGMT-ACCESS', $script);
        $this->assertStringContainsString('/tool mac-server mac-winbox set allowed-interface-list=MGMT-ACCESS', $script);
        $this->assertStringContainsString('/ip dhcp-server add name=dhcp-mgmt interface=vlan-mgmt', $script);
        $this->assertStringContainsString('/ip dhcp-client add interface=$wan1 add-default-route=yes use-peer-dns=no disabled=no', $script);
        $this->assertStringContainsString('/ip dhcp-server add name=dhcp-hotspot interface=vlan-hotspot', $script);
        $this->assertStringContainsString('/ip hotspot add name=mms-hotspot interface=vlan-hotspot', $script);
        $this->assertStringContainsString('login-by=http-pap,http-chap,cookie,mac-cookie', $script);
        $this->assertStringContainsString('/radius add address=10.8.0.1 secret="radius-secret" service=hotspot,ppp', $script);
        $this->assertStringContainsString('/queue type add name=pcq-hotspot-down kind=pcq', $script);
        $this->assertStringContainsString('Realtime voice/video small UDP upload', $script);
        $this->assertStringContainsString('MMS POS = WPA2/WPA3 SSID tagged VLAN 50', $script);
        $this->assertStringContainsString('/ip hotspot profile add name=mms-pos-profile use-radius=yes login-by=mac mac-auth-password="'.RadiusProvisioningService::POS_MAC_AUTH_PASSWORD.'" radius-accounting=yes', $script);
        $this->assertStringContainsString('/ip hotspot add name=mms-pos interface=vlan-pos address-pool=pool-pos profile=mms-pos-profile disabled=no', $script);
        $this->assertStringNotContainsString('# /ip hotspot profile add name=mms-pos-profile', $script);
        $this->assertStringContainsString('/system scheduler add name=mms-refresh-bandwidth interval=10m', $script);
        $this->assertStringNotContainsString('/interface wifi add name=$staffWifiInterface', $script);
        $this->assertStringNotContainsString('ssid="MMS Staff"', $script);
        $this->assertStringContainsString('/ip firewall filter add chain=input in-interface=wg-saas action=accept comment="Allow MMS Radius tunnel (WireGuard)"', $script);
        $this->assertStringNotContainsString('in-interface=zerotier1 action=accept', $script);
    }

    public function test_fresh_infrastructure_firewall_accepts_zerotier_instead_of_wireguard_for_a_zerotier_only_router(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.zerotier.pi_ip' => '10.9.0.1',
            'services.zerotier.network_id' => 'abcd1234abcd1234',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'zerotier-only-fresh-infra',
            'wireguard_internal_ip' => '10.8.0.41',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'zerotier',
            'zerotier_ip' => '10.9.0.41',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        // Confirmed live 2026-09-21: a ZeroTier-only router applying this script lost RouterOS
        // API access entirely -- the firewall's input chain only ever accepted "wg-saas", with
        // no equivalent for "zerotier1", so the catch-all "drop everything not WAN" rule
        // silently blocked the Pi's incoming API connection despite the tunnel itself being up.
        $this->assertStringContainsString('/ip firewall filter add chain=input in-interface=zerotier1 action=accept comment="Allow MMS Radius tunnel (ZeroTier)"', $script);
        $this->assertStringNotContainsString('in-interface=wg-saas action=accept', $script);
    }

    public function test_extra_hotspot_ports_get_their_own_untagged_line_and_leave_the_shared_catch_all(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'extra-ports-router',
            'wireguard_internal_ip' => '10.8.0.30',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'extra_hotspot_ports' => 'ether5,ether6,ether7',
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString(
            '/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=ether5,ether6,ether7 vlan-ids=20',
            $script
        );
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=ether5 pvid=$hotspotVlan comment="Extra hotspot access port"', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=ether6 pvid=$hotspotVlan comment="Extra hotspot access port"', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=ether7 pvid=$hotspotVlan comment="Extra hotspot access port"', $script);

        // Hotspot's VLAN ID (20) must appear on exactly this one dedicated line -- never
        // also inside the shared tagged-only catch-all line, which would double-emit it.
        $this->assertStringNotContainsString('vlan-ids=20,', $script);
        $this->assertStringNotContainsString(',20 ', $script);
        $this->assertStringContainsString('vlan-ids=30,40,50', $script);
    }

    public function test_extra_mgmt_ports_merge_into_the_existing_mgmt_untagged_line(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'extra-mgmt-router',
            'wireguard_internal_ip' => '10.8.0.31',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'extra_mgmt_ports' => 'ether2',
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString(
            '/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=ether3,ether2 vlan-ids=10',
            $script
        );
        $this->assertStringContainsString('/interface bridge port add bridge=$lanBridge interface=ether2 pvid=$mgmtVlan comment="Extra management access port"', $script);
        $this->assertStringContainsString('/interface list member add list=MGMT-ACCESS interface=ether2', $script);
    }

    public function test_mac_server_is_locked_to_the_management_ports_not_wide_open(): void
    {
        // Confirmed live 2026-09-20: /ip firewall filter only ever sees IP traffic --
        // Winbox's MAC-address "Neighbors" discovery (and MAC-Telnet) is a separate
        // Layer 2 mechanism that bypasses the IP firewall entirely and is wide open on
        // every interface by default, so a laptop on a non-mgmt port (e.g. an extra
        // hotspot port) could still reach the router over Winbox by MAC address alone.
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'mac-server-router',
            'wireguard_internal_ip' => '10.8.0.33',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringNotContainsString('allowed-interface-list=all', $script);
        $this->assertStringContainsString('/tool mac-server set allowed-interface-list=MGMT-ACCESS', $script);
        $this->assertStringContainsString('/tool mac-server mac-winbox set allowed-interface-list=MGMT-ACCESS', $script);
    }

    public function test_extra_ports_merge_into_the_builtin_wifi_branchs_untagged_clauses(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'extra-ports-wifi-router',
            'wireguard_internal_ip' => '10.8.0.32',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'enable_builtin_wifi' => true,
                'extra_hotspot_ports' => 'ether5',
                'extra_staff_ports' => 'ether6',
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('untagged=wifi1,ether5 vlan-ids=20', $script);
        $this->assertStringContainsString('untagged=wifi-staff,ether6 vlan-ids=30', $script);
    }

    public function test_custom_hotspot_and_pos_ssid_flow_into_the_builtin_wifi_script(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'ssid-test-router',
            'wireguard_internal_ip' => '10.8.0.40',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'small_hotspot',
                'enable_builtin_wifi' => true,
                'hotspot_ssid' => 'Cafe Free WiFi',
                'pos_ssid' => 'Cafe Till',
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('ssid="Cafe Free WiFi" security=mms-open-hotspot-sec', $script);
        $this->assertStringContainsString('ssid="Cafe Till" security=mms-pos-sec', $script);
        $this->assertStringContainsString('# Cafe Free WiFi = open SSID tagged VLAN', $script);
        $this->assertStringContainsString('# Cafe Till = WPA2/WPA3 SSID tagged VLAN', $script);
        $this->assertStringNotContainsString('ssid="MMS Hotspot"', $script);
        $this->assertStringNotContainsString('ssid="MMS POS"', $script);
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
        $this->assertStringNotContainsString('mms-pos-profile', $script);
        $this->assertStringNotContainsString('name=mms-pos ', $script);
    }

    public function test_the_ap_switch_trunk_port_only_admits_tagged_frames(): void
    {
        // Confirmed live 2026-09-19: without this, the trunk port's default
        // PVID (1, since nothing else was ever set) let RouterOS silently
        // accept untagged frames and auto-create a dynamic VLAN 1 entry for
        // them -- with no DHCP server or L3 interface on VLAN 1, an
        // external switch that hadn't actually applied its own VLAN
        // tagging yet would look like a dead port instead of a diagnosable
        // "still sending untagged" problem.
        $router = new Router([
            'nas_identifier' => 'trunk-test-router',
            'wireguard_internal_ip' => '10.8.0.21',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString(
            '/interface bridge port add bridge=$lanBridge interface=$trunkPort frame-types=admit-only-vlan-tagged',
            $script
        );
    }

    public function test_it_generates_builtin_wifi_hotspot_script(): void
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

        // Built-in Wi-Fi is an independent toggle (not a named hardware-specific profile
        // like the removed "l009_builtin_wifi" used to be) -- any bandwidth/VLAN template
        // can have it on. wan2 is set explicitly since it's no longer profile-implied.
        $router = new Router([
            'nas_identifier' => 'l009-test-router',
            'wireguard_internal_ip' => '10.8.0.30',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'small_hotspot',
                'enable_builtin_wifi' => true,
                'enable_mgmt_wifi' => true,
                'wan2' => 'ether7',
                'hotspot_gateway' => '10.5.50.1/24',
                'hotspot_network' => '10.5.50.0/24',
                'hotspot_pool' => '10.5.50.10-10.5.50.250',
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('Profile: Small hotspot', $script);
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
        $this->assertStringContainsString('No trusted MMS Staff devices registered', $script);
        $this->assertStringContainsString('No trusted MMS Mgmt devices registered', $script);
        $this->assertStringNotContainsString('/interface wifi access-list add', $script);
    }

    public function test_builtin_wifi_script_restricts_staff_and_mgmt_wifi_to_registered_devices(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'L009 Router',
            'nas_identifier' => 'l009-router',
            'wireguard_internal_ip' => '10.8.0.31',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'small_hotspot',
                'enable_builtin_wifi' => true,
                'enable_mgmt_wifi' => true,
            ],
        ]);

        $staffDevice = TrustedWifiDevice::create([
            'shop_id' => $shop->id,
            'network' => TrustedWifiDevice::NETWORK_STAFF,
            'device_name' => "Tolu's Laptop",
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'is_active' => true,
        ]);
        TrustedWifiDevice::create([
            'shop_id' => $shop->id,
            'network' => TrustedWifiDevice::NETWORK_STAFF,
            'device_name' => 'Expired Contractor Laptop',
            'mac_address' => 'AA:BB:CC:DD:EE:02',
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);
        $mgmtDevice = TrustedWifiDevice::create([
            'shop_id' => $shop->id,
            'network' => TrustedWifiDevice::NETWORK_MGMT,
            'device_name' => 'Admin Phone',
            'mac_address' => 'AA:BB:CC:DD:EE:03',
            'is_active' => true,
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('/interface wifi access-list add interface=$staffWifiInterface mac-address='.$staffDevice->mac_address.' action=accept', $script);
        $this->assertStringContainsString('/interface wifi access-list add interface=$staffWifiInterface action=reject', $script);
        $this->assertStringNotContainsString('AA:BB:CC:DD:EE:02', $script);
        $this->assertStringContainsString('/interface wifi access-list add interface=$mgmtWifiInterface mac-address='.$mgmtDevice->mac_address.' action=accept', $script);
        $this->assertStringContainsString('/interface wifi access-list add interface=$mgmtWifiInterface action=reject', $script);
    }

    public function test_builtin_wifi_script_can_omit_staff_and_management_wifi(): void
    {
        config([
            'app.url' => 'https://mmsradius.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'hotspot-only-router',
            'wireguard_internal_ip' => '10.8.0.32',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'small_hotspot',
                'enable_builtin_wifi' => true,
                'enable_staff' => false,
                'enable_pos' => false,
                'enable_mgmt_wifi' => false,
            ],
        ]);

        $script = app(MikroTikProvisioningService::class)->generateFreshInfrastructureScript($router);

        $this->assertStringContainsString('Staff VLAN/SSID is disabled', $script);
        $this->assertStringContainsString('Management virtual Wi-Fi is disabled', $script);
        $this->assertStringNotContainsString('name=vlan-staff', $script);
        $this->assertStringNotContainsString('ssid="MMS Staff"', $script);
        $this->assertStringNotContainsString('ssid="MMS Mgmt"', $script);
        $this->assertStringContainsString('/interface bridge vlan add bridge=bridge-lan tagged=bridge-lan,ether2 untagged=ether3 vlan-ids=10', $script);
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

    public function test_zerotier_only_script_has_no_wireguard_lines_and_joins_the_network(): void
    {
        config([
            'services.radius.server_ip' => '10.8.0.1',
            'services.zerotier.pi_ip' => '10.9.0.1',
            'services.zerotier.network_id' => 'abcd1234abcd1234',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'zerotier-only-router',
            'wireguard_internal_ip' => '10.8.0.41',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'zerotier',
            'zerotier_ip' => '10.9.0.41',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringNotContainsString('/interface wireguard', $script);
        $this->assertStringContainsString('/zerotier enable zt1', $script);
        $this->assertStringContainsString('/zerotier interface add network=abcd1234abcd1234 instance=zt1', $script);
        // Confirmed live 2026-09-21: joining the network alone leaves the router with no
        // IP address bound to its ZeroTier interface at all -- the tunnel and controller
        // authorization can both show fine while the RouterOS API is still unreachable
        // over it, since nothing else ever assigns this address.
        $this->assertStringContainsString('/ip address add address=10.9.0.41/24 interface=zerotier1 comment="MMS Radius ZeroTier IP"', $script);
        $this->assertStringContainsString('/radius add address=10.9.0.1 secret="radius-secret" service=hotspot,ppp', $script);
        $this->assertStringNotContainsString('priority=', $script);
    }

    public function test_zerotier_script_omits_the_ip_address_line_until_a_zerotier_ip_is_saved(): void
    {
        config([
            'services.radius.server_ip' => '10.8.0.1',
            'services.zerotier.pi_ip' => '10.9.0.1',
            'services.zerotier.network_id' => 'abcd1234abcd1234',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'zerotier-no-ip-yet-router',
            'wireguard_internal_ip' => '10.8.0.42',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'zerotier',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/zerotier interface add network=abcd1234abcd1234 instance=zt1', $script);
        // This trailing comment only ever appears on the real /ip address add command
        // (its absence confirms that command was correctly skipped, not emitted with a
        // blank address) -- the explanatory fallback line below also mentions
        // "interface=zerotier1" in passing, so checking for that alone isn't distinctive.
        $this->assertStringNotContainsString('comment="MMS Radius ZeroTier IP"', $script);
        $this->assertStringContainsString('re-generate this script to add the "/ip address add ... interface=zerotier1" line', $script);
    }

    public function test_wireguard_zerotier_script_has_both_radius_entries_in_failover_order(): void
    {
        config([
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.zerotier.pi_ip' => '10.9.0.1',
            'services.zerotier.network_id' => 'abcd1234abcd1234',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'dual-tunnel-router',
            'wireguard_internal_ip' => '10.8.0.42',
            'wireguard_private_key' => 'client-private-key',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'wireguard_zerotier',
            'zerotier_ip' => '10.9.0.42',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/interface wireguard add name=wg-saas', $script);
        $this->assertStringContainsString('/zerotier enable zt1', $script);

        // RouterOS has no "priority" property on /radius at all (confirmed
        // live 2026-08-19 -- it rejects one outright); failover order is
        // purely list order, so WireGuard's line must appear before
        // ZeroTier's for RouterOS to try it first.
        $this->assertStringNotContainsString('priority=', $script);
        $wireguardPos = strpos($script, '/radius add address=10.8.0.1 secret="radius-secret" service=hotspot,ppp authentication-port=1812 accounting-port=1813 timeout=1000ms');
        $zerotierPos = strpos($script, '/radius add address=10.9.0.1 secret="radius-secret" service=hotspot,ppp authentication-port=1812 accounting-port=1813 timeout=1000ms');
        $this->assertNotFalse($wireguardPos);
        $this->assertNotFalse($zerotierPos);
        $this->assertLessThan($zerotierPos, $wireguardPos);
    }

    public function test_api_service_address_restriction_includes_both_subnets_in_dual_mode(): void
    {
        config([
            'services.zerotier.ip_prefix' => '10.9.0',
        ]);

        $router = new Router([
            'nas_identifier' => 'dual-tunnel-router-2',
            'wireguard_internal_ip' => '10.8.0.43',
            'shared_secret' => 'radius-secret',
            'api_username' => 'mmsradius-api',
            'api_password' => 'api-password',
            'tunnel_mode' => 'wireguard_zerotier',
            'zerotier_ip' => '10.9.0.43',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/ip service set api disabled=no port=8728 address=10.8.0.0/24,10.9.0.0/24', $script);
    }

    public function test_it_lists_flexible_infrastructure_profiles(): void
    {
        $profiles = app(MikroTikProvisioningService::class)->infrastructureProfiles();

        $this->assertSame(['starlink_plaza', 'small_hotspot', 'pppoe_isp'], array_keys($profiles));
    }

    public function test_login_template_uses_public_hotspot_portal_url_when_configured(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8001',
            'services.mikrotik.portal_url' => 'https://mmsradius.com/hotspot/portal',
        ]);

        $template = app(MikroTikProvisioningService::class)->generateLoginTemplate();

        $this->assertStringContainsString('&link-login-only=\' + encodeURIComponent(\'$(link-login-only)\')', $template);
        $this->assertStringContainsString("var portal = 'https://mmsradius.com/hotspot/portal'", $template);
        $this->assertStringNotContainsString('http-equiv="refresh"', $template);
        $this->assertStringNotContainsString('http://127.0.0.1:8001/hotspot/portal', $template);
    }
}
