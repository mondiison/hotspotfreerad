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

    public function test_ros6_explicitly_enables_the_wireless_interface_before_configuring_it(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
        ]));

        $enablePosition = strpos($result['script_a'], '/interface wireless enable wlan1');
        $setPosition = strpos($result['script_a'], '/interface wireless set wlan1 mode=');

        $this->assertNotFalse($enablePosition);
        $this->assertNotFalse($setPosition);
        $this->assertLessThan($setPosition, $enablePosition);
    }

    public function test_ros7_explicitly_enables_the_wifi_interface_before_configuring_it(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'radio_a' => $this->radio(['ros_version' => '7', 'wireless_interface' => 'wifi1']),
        ]));

        $enablePosition = strpos($result['script_a'], '/interface wifi enable [find default-name=wifi1]');
        $setPosition = strpos($result['script_a'], '/interface wifi set [find default-name=wifi1] configuration=');

        $this->assertNotFalse($enablePosition);
        $this->assertNotFalse($setPosition);
        $this->assertLessThan($setPosition, $enablePosition);
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

    public function test_a_lan_port_is_added_as_a_bridge_port_and_enabled_when_provided(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'bridged',
            'radio_a' => $this->radio(['lan_port' => 'ether1']),
        ]));

        $this->assertStringContainsString('/interface ethernet set ether1 disabled=no', $result['script_a']);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-ptp interface=ether1', $result['script_a']);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-ptp interface=wifi1', $result['script_a']);
    }

    public function test_no_lan_port_leaves_a_comment_instead_of_a_useless_bridge(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'bridged',
            'radio_a' => $this->radio(['lan_port' => null]),
        ]));

        $this->assertStringNotContainsString('/interface ethernet set', $result['script_a']);
        $this->assertStringContainsString('# No LAN port configured', $result['script_a']);
    }

    public function test_ros6_sets_band_country_and_frequency_mode_in_the_same_command_as_frequency(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'country' => 'no_country_set',
            'frequency_mode' => 'regulatory-domain',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1', 'band' => '5ghz-a/n/ac']),
        ]));

        $this->assertMatchesRegularExpression(
            '/\/interface wireless set wlan1 mode=\S+ band=5ghz-a\/n\/ac ssid="ptp-link" frequency=5745 channel-width=20mhz country="no_country_set" frequency-mode=regulatory-domain/',
            $result['script_a']
        );
    }

    public function test_ros7_sets_band_and_country_on_the_wifi_configuration(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'country' => 'no_country_set',
            'radio_a' => $this->radio(['ros_version' => '7', 'wireless_interface' => 'wifi1', 'band' => '5ghz-ac']),
        ]));

        $this->assertStringContainsString('country="no_country_set"', $result['script_a']);
        $this->assertStringContainsString('channel.band=5ghz-ac', $result['script_a']);
    }

    public function test_superchannel_frequency_mode_gets_a_compliance_warning_comment(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'frequency_mode' => 'superchannel',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
        ]));

        $this->assertStringContainsString('frequency-mode=superchannel', $result['script_a']);
        $this->assertStringContainsString('bypasses country regulatory limits', $result['script_a']);
    }

    public function test_nv2_wireless_protocol_is_emitted_on_ros6(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'wireless_protocol' => 'nv2',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
        ]));

        $this->assertStringContainsString('wireless-protocol=nv2', $result['script_a']);
    }

    public function test_802_11_is_the_default_and_emits_no_wireless_protocol_property(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'wireless_protocol' => '802.11',
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
        ]));

        $this->assertStringNotContainsString('wireless-protocol=', $result['script_a']);
    }

    public function test_hide_ssid_true_sets_hide_ssid_yes_on_both_ros_versions(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'hide_ssid' => true,
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
            'radio_b' => $this->radio(['ros_version' => '7', 'wireless_interface' => 'wifi1'], 'B'),
        ]));

        $this->assertStringContainsString('hide-ssid=yes', $result['script_a']);
        $this->assertStringContainsString('hide-ssid=yes', $result['script_b']);
    }

    public function test_skip_dfs_channels_only_appears_on_ros7(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'skip_dfs_channels' => true,
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1']),
            'radio_b' => $this->radio(['ros_version' => '7', 'wireless_interface' => 'wifi1'], 'B'),
        ]));

        $this->assertStringNotContainsString('skip-dfs-channels', $result['script_a']);
        $this->assertStringContainsString('channel.skip-dfs-channels=all', $result['script_b']);
    }

    public function test_antenna_gain_is_only_emitted_on_ros6_when_provided(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1', 'antenna_gain_dbi' => 16]),
            'radio_b' => $this->radio(['ros_version' => '7', 'wireless_interface' => 'wifi1', 'antenna_gain_dbi' => 16], 'B'),
        ]));

        $this->assertStringContainsString('antenna-gain=16', $result['script_a']);
        $this->assertStringNotContainsString('antenna-gain=', $result['script_b']);
    }

    public function test_antenna_gain_is_omitted_when_not_provided(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'radio_a' => $this->radio(['ros_version' => '6', 'wireless_interface' => 'wlan1', 'antenna_gain_dbi' => null]),
        ]));

        $this->assertStringNotContainsString('antenna-gain=', $result['script_a']);
    }

    public function test_ros7_station_uses_station_bridge_in_bridged_link_mode(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'bridged',
            'ap_end' => 'radio_a',
        ]));

        $this->assertStringContainsString('mode=station-bridge ', $result['script_b']);
        $this->assertStringNotContainsString('mode=station ', $result['script_b']);
    }

    public function test_ros7_station_uses_plain_station_in_routed_link_mode(): void
    {
        $result = (new StandalonePtpScriptGenerator)->generate($this->config([
            'link_mode' => 'routed',
            'ap_end' => 'radio_a',
        ]));

        $this->assertStringContainsString('mode=station ', $result['script_b']);
        $this->assertStringNotContainsString('station-bridge', $result['script_b']);
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
            'country' => 'no_country_set',
            'frequency_mode' => 'regulatory-domain',
            'wireless_protocol' => '802.11',
            'hide_ssid' => true,
            'skip_dfs_channels' => true,
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
        $merged = array_merge([
            'identity' => 'Radio '.$label,
            'ros_version' => '7',
            'wireless_interface' => 'wifi1',
            'band' => null,
            'antenna_gain_dbi' => null,
            'lan_port' => null,
            'add_remote_route' => false,
            'remote_network' => null,
        ], $overrides);

        if ($merged['band'] === null) {
            $merged['band'] = $merged['ros_version'] === '6' ? '5ghz-a/n/ac' : '5ghz-ac';
        }

        return $merged;
    }
}
