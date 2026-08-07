<?php

namespace Tests\Unit;

use App\Services\StandaloneHotspotScriptGenerator;
use Tests\TestCase;

class StandaloneHotspotScriptGeneratorTest extends TestCase
{
    public function test_it_never_emits_wireguard_or_platform_api_user_lines(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config());

        $this->assertStringNotContainsString('/interface wireguard', $result['script']);
        $this->assertStringNotContainsString('mmsradius-api-group', $result['script']);
    }

    public function test_ros6_never_emits_radius_at_all(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config(['ros_version' => '6']));

        $this->assertStringNotContainsString('/radius add', $result['script']);
    }

    public function test_ros7_uses_user_manager_as_a_self_hosted_radius_server_only(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config(['ros_version' => '7']));

        $this->assertStringContainsString('/user-manager set enabled=yes', $result['script']);
        $this->assertStringContainsString('/user-manager router add address=127.0.0.1', $result['script']);
        $this->assertStringContainsString('/radius add address=127.0.0.1', $result['script']);
        $this->assertStringContainsString('use-radius=yes', $result['script']);
    }

    public function test_ros7_profiles_start_validity_at_first_login(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 10, 'upload_mbps' => 2, 'session_minutes' => 1440, 'shared_users' => 2, 'voucher_count' => 0, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertStringContainsString('/user-manager limitation add name="daily-limit" rate-limit-rx=2M rate-limit-tx=10M', $result['script']);
        $this->assertStringContainsString('/user-manager profile add name="daily" validity=1440m starts-when=first-auth', $result['script']);
        $this->assertStringContainsString('/user-manager profile-limitation add profile="daily" limitation="daily-limit"', $result['script']);
    }

    public function test_ros7_vouchers_become_user_manager_users(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 3, 'voucher_count' => 2, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertStringContainsString('/user-manager user add name="'.$voucher['code'].'" password="'.$voucher['code'].'" shared-users=3', $result['script']);
            $this->assertStringContainsString('/user-manager user-profile add user="'.$voucher['code'].'" profile="daily"', $result['script']);
        }
    }

    public function test_ros6_classic_hotspot_gets_an_on_login_expiry_script(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '6',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 10, 'upload_mbps' => 2, 'session_minutes' => 1440, 'shared_users' => 2, 'voucher_count' => 0, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertStringContainsString('/ip hotspot profile set hotspot-profile on-login=', $result['script']);
        $this->assertStringContainsString('/system scheduler add name=$sn interval=$st', $result['script']);
        $this->assertStringContainsString(
            '/ip hotspot user profile add name="daily" rate-limit=2M/10M session-timeout=86400s shared-users=2',
            $result['script']
        );
        $this->assertStringNotContainsString('starts-when', $result['script']);
    }

    public function test_ros6_vouchers_become_classic_hotspot_users(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '6',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 2, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertStringContainsString('/ip hotspot user add name="'.$voucher['code'].'" password="'.$voucher['code'].'" profile="daily"', $result['script']);
        }
    }

    public function test_ros7_wireless_uses_wifiwave2_syntax(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'has_wireless' => true,
            'wireless_interface' => 'wifi1',
        ]));

        $this->assertStringContainsString('/interface wifi security add name=hotspot-open', $result['script']);
        $this->assertStringContainsString('[find default-name=wifi1]', $result['script']);
        $this->assertStringNotContainsString('/interface wireless ', $result['script']);
    }

    public function test_ros6_wireless_uses_legacy_syntax(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '6',
            'has_wireless' => true,
            'wireless_interface' => 'wlan1',
        ]));

        $this->assertStringContainsString('/interface wireless security-profiles add name=hotspot-open mode=none', $result['script']);
        $this->assertStringContainsString('/interface wireless set wlan1 mode=ap-bridge', $result['script']);
        $this->assertStringNotContainsString('/interface wifi ', $result['script']);
    }

    public function test_wired_only_router_skips_wireless_and_notes_external_ap(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'has_wireless' => false,
        ]));

        $this->assertStringNotContainsString('/interface wifi', $result['script']);
        $this->assertStringNotContainsString('/interface wireless', $result['script']);
        $this->assertStringContainsString('external access point', $result['script']);
    }

    public function test_ethernet_port_count_bridges_every_port_except_wan(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'wan_port' => 'ether3',
            'ethernet_port_count' => 5,
        ]));

        foreach (['ether1', 'ether2', 'ether4', 'ether5'] as $port) {
            $this->assertStringContainsString('/interface bridge port add bridge=bridge-hotspot interface='.$port, $result['script']);
        }
        $this->assertStringNotContainsString('/interface bridge port add bridge=bridge-hotspot interface=ether3', $result['script']);
    }

    public function test_router_identity_and_hotspot_name_are_independent(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'router_identity' => 'Bebeji-Router01',
            'hotspot_name' => 'Bebeji Free Wi-Fi',
        ]));

        $this->assertStringContainsString('/system identity set name="Bebeji-Router01"', $result['script']);
        $this->assertStringContainsString('ssid="Bebeji Free Wi-Fi"', $result['script']);
        $this->assertStringNotContainsString('/system identity set name="Bebeji Free Wi-Fi"', $result['script']);
    }

    public function test_management_network_adds_isolated_bridge_and_wpa2_ssid_when_wireless(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'has_wireless' => true,
            'ros_version' => '7',
            'wireless_interface' => 'wifi1',
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
            'mgmt_password' => 'super-secret-1',
        ]));

        $this->assertStringContainsString('bridge-mgmt', $result['script']);
        $this->assertStringContainsString('passphrase="super-secret-1"', $result['script']);
        $this->assertStringContainsString('Hotspot clients cannot reach the management network', $result['script']);
    }

    public function test_management_network_locks_router_admin_services_to_its_subnet(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
        ]));

        $this->assertStringContainsString('/ip service set www address=10.10.20.0/24', $result['script']);
        $this->assertStringContainsString('/ip service set www-ssl address=10.10.20.0/24', $result['script']);
        $this->assertStringContainsString('/ip service set winbox address=10.10.20.0/24', $result['script']);
        $this->assertStringContainsString('/ip service set ssh address=10.10.20.0/24', $result['script']);
    }

    public function test_management_network_disabled_omits_mgmt_bridge_and_service_lockdown(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'enable_mgmt_network' => false,
        ]));

        $this->assertStringNotContainsString('bridge-mgmt', $result['script']);
        $this->assertStringNotContainsString('/ip service set www', $result['script']);
        $this->assertStringContainsString('Management network is disabled', $result['script']);
    }

    public function test_management_network_on_wired_only_router_skips_ssid_and_notes_dedicated_port(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'has_wireless' => false,
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
            'mgmt_password' => '',
        ]));

        $this->assertStringContainsString('bridge-mgmt', $result['script']);
        $this->assertStringContainsString('Wire a dedicated port to bridge-mgmt', $result['script']);
        $this->assertStringNotContainsString('security-profile=mgmt-sec', $result['script']);
        $this->assertStringNotContainsString('security=mgmt-sec', $result['script']);
    }

    public function test_voucher_codes_are_generated_per_profile_and_unique(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 5, 'voucher_prefix' => '', 'voucher_code_length' => 8],
                ['name' => 'Weekly', 'download_mbps' => 8, 'upload_mbps' => 8, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 3, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertCount(8, $result['vouchers']);
        $this->assertCount(5, array_filter($result['vouchers'], fn ($v) => $v['profile'] === 'Daily'));
        $this->assertCount(3, array_filter($result['vouchers'], fn ($v) => $v['profile'] === 'Weekly'));

        $codes = array_column($result['vouchers'], 'code');
        $this->assertSame($codes, array_unique($codes));
    }

    public function test_voucher_prefix_and_code_length_are_respected(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 4, 'voucher_prefix' => 'DLY-', 'voucher_code_length' => 6],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertStringStartsWith('DLY-', $voucher['code']);
            $this->assertSame(10, strlen($voucher['code']));
        }
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'router_identity' => 'Test Router',
            'hotspot_name' => 'Test Hotspot',
            'ros_version' => '7',
            'has_wireless' => true,
            'wan_port' => 'ether1',
            'ethernet_port_count' => 5,
            'wireless_interface' => 'wifi1',
            'hotspot_network' => '10.10.10.1/24',
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
            'mgmt_password' => 'password123',
            'profiles' => [
                ['name' => '1 Hour', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 2, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ], $overrides);
    }
}
