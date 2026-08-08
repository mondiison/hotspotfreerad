<?php

namespace Tests\Unit;

use App\Services\StandalonePtpScriptGenerator;
use Tests\TestCase;

class StandalonePtpScriptGeneratorTest extends TestCase
{
    public function test_it_assigns_the_first_two_usable_ptp_subnet_addresses(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config(['ptp_subnet' => '10.20.30.0/30']));

        $this->assertStringContainsString('/ip address add address=10.20.30.1/30 interface=wifi1', $result['script_a']);
        $this->assertStringContainsString('/ip address add address=10.20.30.2/30 interface=wifi1', $result['script_b']);
    }

    public function test_it_derives_ptp_addresses_correctly_for_a_wider_subnet(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config(['ptp_subnet' => '192.168.5.0/28']));

        $this->assertStringContainsString('/ip address add address=192.168.5.1/28 interface=wifi1', $result['script_a']);
        $this->assertStringContainsString('/ip address add address=192.168.5.2/28 interface=wifi1', $result['script_b']);
    }

    public function test_ros6_uses_legacy_wireless_syntax(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
        ]));

        $this->assertStringContainsString('/interface wireless security-profiles add name=ptp-sec', $result['script_a']);
        $this->assertStringContainsString('/interface wireless set wlan1 mode=bridge', $result['script_a']);
        $this->assertStringNotContainsString('/interface wifi', $result['script_a']);
    }

    public function test_ros7_uses_wifi_package_syntax(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'radio_a' => $this->radio(['ros_version' => '7', 'wireless_interface' => 'wifi1']),
        ]));

        $this->assertStringContainsString('/interface wifi security add name=ptp-sec', $result['script_a']);
        $this->assertStringContainsString('/interface wifi configuration add name=ptp-ap-cfg mode=ap', $result['script_a']);
        $this->assertStringContainsString('/interface wifi set [find default-name=wifi1] configuration=ptp-ap-cfg', $result['script_a']);
        $this->assertStringNotContainsString('/interface wireless ', $result['script_a']);
    }

    public function test_the_ap_end_gets_ap_mode_and_the_other_end_gets_station_mode(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config(['ap_end' => 'radio_a']));

        $this->assertStringContainsString('mode=ap ', $result['script_a']);
        $this->assertStringContainsString('mode=station ', $result['script_b']);
    }

    public function test_bridged_mode_uses_pseudobridge_and_bridge_station_modes_on_ros6(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'bridged',
            'ap_end' => 'radio_a',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
            'radio_b' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1'], 'B'),
        ]));

        $this->assertStringContainsString('mode=bridge ', $result['script_a']);
        $this->assertStringContainsString('mode=station-pseudobridge ', $result['script_b']);
    }

    public function test_bridged_mode_creates_a_bridge_interface_and_addresses_it(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'bridged',
            'ptp_subnet' => '10.20.30.0/30',
        ]));

        $this->assertStringContainsString('/interface bridge add name=bridge-ptp', $result['script_a']);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-ptp interface=wifi1', $result['script_a']);
        $this->assertStringContainsString('/ip address add address=10.20.30.1/30 interface=bridge-ptp', $result['script_a']);
        $this->assertStringNotContainsString('/ip address add address=10.20.30.1/30 interface=wifi1', $result['script_a']);
    }

    public function test_routed_mode_does_not_create_a_bridge_and_addresses_the_wireless_interface_directly(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'routed',
            'ptp_subnet' => '10.20.30.0/30',
        ]));

        $this->assertStringNotContainsString('/interface bridge add', $result['script_a']);
        $this->assertStringNotContainsString('/interface bridge port', $result['script_a']);
        $this->assertStringContainsString('/ip address add address=10.20.30.1/30 interface=wifi1', $result['script_a']);
    }

    public function test_routed_mode_uses_bridge_and_station_on_ros6(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'routed',
            'ap_end' => 'radio_a',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
            'radio_b' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1'], 'B'),
        ]));

        $this->assertStringContainsString('mode=bridge ', $result['script_a']);
        $this->assertStringContainsString('mode=station ', $result['script_b']);
    }

    public function test_ptp_topology_uses_bridge_mode_on_ros6(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_topology' => 'ptp',
            'ap_end' => 'radio_a',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
            'radio_b' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1'], 'B'),
        ]));

        $this->assertStringContainsString('mode=bridge ', $result['script_a']);
        $this->assertStringNotContainsString('mode=ap-bridge', $result['script_a']);
    }

    public function test_ptmp_topology_uses_ap_bridge_mode_on_ros6(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_topology' => 'ptmp',
            'ap_end' => 'radio_a',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
            'radio_b' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1'], 'B'),
        ]));

        $this->assertStringContainsString('mode=ap-bridge ', $result['script_a']);
        $this->assertStringContainsString('needs a higher wireless license tier', $result['script_a']);
    }

    public function test_ptmp_topology_does_not_change_ros7_ap_mode(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_topology' => 'ptmp',
            'ap_end' => 'radio_a',
        ]));

        $this->assertStringContainsString('/interface wifi configuration add name=ptp-ap-cfg mode=ap ', $result['script_a']);
        $this->assertStringNotContainsString('ap-bridge', $result['script_a']);
    }

    public function test_distance_km_is_converted_to_meters(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config(['distance_km' => 4.2]));

        $this->assertStringContainsString('distance=4200', $result['script_a']);
    }

    public function test_wpa2_wpa3_mixed_security_appears_on_ros7(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config(['security_mode' => 'wpa2_wpa3']));

        $this->assertStringContainsString('authentication-types=wpa2-psk,wpa3-psk', $result['script_a']);
    }

    public function test_wpa2_only_security_appears_by_default(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config(['security_mode' => 'wpa2']));

        $this->assertStringContainsString('authentication-types=wpa2-psk', $result['script_a']);
        $this->assertStringNotContainsString('wpa3-psk', $result['script_a']);
    }

    public function test_remote_route_only_appears_when_enabled(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'ptp_subnet' => '10.20.30.0/30',
            'radio_a' => $this->radio(['add_remote_route' => true, 'remote_network' => '192.168.10.0/24']),
            'radio_b' => $this->radio(['add_remote_route' => false, 'remote_network' => null], 'B'),
        ]));

        $this->assertStringContainsString('/ip route add dst-address=192.168.10.0/24 gateway=10.20.30.2', $result['script_a']);
        $this->assertStringNotContainsString('/ip route add', $result['script_b']);
    }

    public function test_it_never_emits_wireguard_or_platform_api_user_lines(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config());

        $this->assertStringNotContainsString('/interface wireguard', $result['script_a']);
        $this->assertStringNotContainsString('mmsradius-api-group', $result['script_a']);
        $this->assertStringNotContainsString('/interface wireguard', $result['script_b']);
        $this->assertStringNotContainsString('mmsradius-api-group', $result['script_b']);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'link_name' => 'Test Link',
            'link_mode' => 'routed',
            'link_topology' => 'ptp',
            'ap_end' => 'radio_a',
            'frequency_mhz' => 5745,
            'channel_width_mhz' => 20,
            'ssid' => 'ptp-link',
            'security_mode' => 'wpa2',
            'psk' => 'supersecretpsk',
            'distance_km' => 1.0,
            'ptp_subnet' => '10.20.30.0/30',
            'radio_a' => $this->radio([], 'A'),
            'radio_b' => $this->radio([], 'B'),
        ], $overrides);
    }

    private function radio(array $overrides = [], string $label = 'A'): array
    {
        return array_merge([
            'identity' => 'Radio '.$label,
            'ros_version' => '7',
            'wireless_interface' => 'wifi1',
            'add_remote_route' => false,
            'remote_network' => null,
        ], $overrides);
    }
}
