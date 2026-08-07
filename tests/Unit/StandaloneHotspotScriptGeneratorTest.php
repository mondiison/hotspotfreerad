<?php

namespace Tests\Unit;

use App\Services\StandaloneHotspotScriptGenerator;
use Tests\TestCase;

class StandaloneHotspotScriptGeneratorTest extends TestCase
{
    public function test_it_never_emits_radius_wireguard_or_api_user_lines(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config());

        $this->assertStringNotContainsString('/radius add', $result['script']);
        $this->assertStringNotContainsString('/interface wireguard', $result['script']);
        $this->assertStringNotContainsString('mmsradius-api-group', $result['script']);
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

    public function test_management_network_disabled_omits_mgmt_bridge(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'enable_mgmt_network' => false,
        ]));

        $this->assertStringNotContainsString('bridge-mgmt', $result['script']);
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

    public function test_profiles_produce_rate_limit_session_timeout_and_shared_users(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 10, 'upload_mbps' => 2, 'session_minutes' => 1440, 'shared_users' => 2, 'voucher_count' => 0],
            ],
        ]));

        $this->assertStringContainsString(
            '/ip hotspot user profile add name="daily" rate-limit=2M/10M session-timeout=86400s shared-users=2',
            $result['script']
        );
    }

    public function test_voucher_codes_are_generated_per_profile_and_unique(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 5],
                ['name' => 'Weekly', 'download_mbps' => 8, 'upload_mbps' => 8, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 3],
            ],
        ]));

        $this->assertCount(8, $result['vouchers']);
        $this->assertCount(5, array_filter($result['vouchers'], fn ($v) => $v['profile'] === 'Daily'));
        $this->assertCount(3, array_filter($result['vouchers'], fn ($v) => $v['profile'] === 'Weekly'));

        $codes = array_column($result['vouchers'], 'code');
        $this->assertSame($codes, array_unique($codes));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertStringContainsString('/ip hotspot user add name="'.$voucher['code'].'" password="'.$voucher['code'].'"', $result['script']);
        }
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'hotspot_name' => 'Test Hotspot',
            'ros_version' => '7',
            'has_wireless' => true,
            'wan_port' => 'ether1',
            'lan_port' => 'ether2',
            'wireless_interface' => 'wifi1',
            'hotspot_network' => '10.10.10.1/24',
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
            'mgmt_password' => 'password123',
            'profiles' => [
                ['name' => '1 Hour', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 2],
            ],
        ], $overrides);
    }
}
