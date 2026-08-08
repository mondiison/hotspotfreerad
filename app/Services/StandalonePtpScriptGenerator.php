<?php

namespace App\Services;

/**
 * Generates MikroTik RouterOS configuration for a point-to-point or
 * point-to-multipoint wireless bridge/backhaul link -- two radios, one
 * AP/master end and one Station end, that must be configured to match (same
 * frequency, channel width, SSID, security). Deliberately self-contained,
 * like `StandaloneHotspotScriptGenerator`: no WireGuard, no HotspotFreeRAD
 * RADIUS server, no Router/Shop/Tenant record, nothing persisted -- the
 * wizard's Livewire state holds everything and this class is stateless.
 * Despite the "PTP" name (kept for continuity with the wizard/route), one
 * session still only produces a script for exactly two radios -- a
 * point-to-multipoint hub is just an AP end whose `link_topology` is
 * `'ptmp'` instead of `'ptp'`, re-run once per station it needs to serve.
 *
 * `link_topology` picks the AP end's RouterOS 6 legacy-wireless master mode:
 * `'ptp'` uses `bridge` (single-peer master mode, included in MikroTik's
 * base/CPE wireless license tier -- works on cheaper dish hardware like the
 * SXTsq); `'ptmp'` uses `ap-bridge` (true multi-client AP mode, gated behind
 * a higher wireless license tier/AP-capable hardware). Using `ap-bridge` for
 * a link that will only ever have one peer is why AP mode was rejected as
 * "not supported" on CPE-class hardware in the first place (confirmed
 * 2026-08-08 from a real error on an SXTsq Lite5) -- `bridge` mode is the
 * correct single-peer choice regardless of routed vs bridged L3 addressing.
 * RouterOS 7's wifi package has no equivalent split -- `mode=ap` covers both
 * topologies there, since that driver doesn't have a separate single-peer
 * master mode the way legacy wireless does.
 *
 * "Bridged" link mode uses `station-pseudobridge` rather than true
 * `station-bridge` -- pseudobridge (translational bridging) works across
 * the widest range of MikroTik wireless hardware, where true station-bridge
 * needs specific chipset/driver support on both ends. The `distance=`
 * property (used here for ACK-timeout auto-tuning on long links) and its
 * exact behavior on `/interface wifi` (RouterOS 7) are not verified against
 * real hardware -- same "best-effort, confirm on your own gear" caveat
 * already used elsewhere in this codebase for unverified RouterOS syntax.
 *
 * A CPE/dish-style radio (SXTsq, LHG, Disc, Metal, etc.) can still be the
 * `'ptp'` AP end (bridge mode is in its base license), but not the `'ptmp'`
 * AP end (ap-bridge needs the higher tier). This class doesn't know or care
 * which radio is CPE-only -- that topology-dependent constraint is enforced
 * upstream, in the wizard's `ap_end` validation
 * (`App\Livewire\Admin\StandalonePtpGenerator`), so an ap-bridge script is
 * simply never generated for a radio marked that way.
 */
class StandalonePtpScriptGenerator
{
    /**
     * @param  array{
     *     link_name: string,
     *     link_mode: string,
     *     link_topology: string,
     *     ap_end: string,
     *     frequency_mhz: int,
     *     channel_width_mhz: int,
     *     ssid: string,
     *     security_mode: string,
     *     psk: string,
     *     distance_km: float,
     *     ptp_subnet: string,
     *     radio_a: array{identity: string, ros_version: string, wireless_interface: string, cpe_only?: bool, lan_port?: ?string, add_remote_route: bool, remote_network: ?string},
     *     radio_b: array{identity: string, ros_version: string, wireless_interface: string, cpe_only?: bool, lan_port?: ?string, add_remote_route: bool, remote_network: ?string},
     * } $config
     * @return array{script_a: string, script_b: string}
     */
    public function generate(array $config): array
    {
        return [
            'script_a' => implode("\n", $this->radioLines($config, 'radio_a', 'radio_b')),
            'script_b' => implode("\n", $this->radioLines($config, 'radio_b', 'radio_a')),
        ];
    }

    /**
     * @return list<string>
     */
    private function radioLines(array $config, string $thisKey, string $otherKey): array
    {
        $radio = $config[$thisKey];
        $isApEnd = $config['ap_end'] === $thisKey;
        $subnet = $this->ptpSubnetParts($config['ptp_subnet']);
        $thisIp = $subnet[$thisKey.'_ip'];
        $otherHost = $subnet[$otherKey.'_host'];

        $addressInterface = $config['link_mode'] === 'bridged' ? 'bridge-ptp' : $radio['wireless_interface'];

        return array_merge(
            $this->headerLines($config, $radio, $isApEnd),
            $this->identityLines($radio),
            $this->wirelessLines($config, $radio, $isApEnd),
            $this->bridgeLines($config, $radio),
            $this->addressingLines($thisIp, $addressInterface),
            $this->remoteRouteLines($radio, $otherHost),
        );
    }

    /**
     * @return list<string>
     */
    private function headerLines(array $config, array $radio, bool $isApEnd): array
    {
        return [
            '# Standalone MikroTik PTP link script -- generated by HotspotFreeRAD PTP Radio Generator',
            '# Link: '.$config['link_name'].' -- this end: '.($isApEnd ? 'AP/master' : 'Station').', RouterOS '.$radio['ros_version'],
            '# This script is self-contained: it does not use this platform\'s RADIUS, WireGuard, or any HotspotFreeRAD account.',
            '# Review the wireless interface name against your actual hardware before pasting.',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function identityLines(array $radio): array
    {
        return [
            '/system identity set name="'.$this->quote($radio['identity']).'"',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function wirelessLines(array $config, array $radio, bool $isApEnd): array
    {
        $interface = $radio['wireless_interface'];
        $ssid = $this->quote($config['ssid']);
        $stationMode = $config['link_mode'] === 'bridged' ? 'station-pseudobridge' : 'station';
        $distanceMeters = (int) round($config['distance_km'] * 1000);

        if ($radio['ros_version'] === '6') {
            $securityLines = $this->legacySecurityLines($config);
            $apMode = $config['link_topology'] === 'ptmp' ? 'ap-bridge' : 'bridge';
            $mode = $isApEnd ? $apMode : $stationMode;

            $lines = [
                // A factory-default wireless interface is administratively disabled,
                // and RouterOS 6 can reject mode=/frequency=/etc changes on a still-
                // disabled interface even when disabled=no is in the same `set`
                // command -- enable it as its own command first, don't rely on the
                // inline disabled=no below alone (kept anyway as a second safeguard).
                '/interface wireless enable '.$interface,
                '/interface wireless set '.$interface
                    .' mode='.$mode
                    .' ssid="'.$ssid.'"'
                    .' frequency='.$config['frequency_mhz']
                    .' channel-width='.$config['channel_width_mhz'].'mhz'
                    .' security-profile=ptp-sec'
                    .' distance='.$distanceMeters
                    .' disabled=no',
                '# distance= auto-tunes ACK timeout for long links -- confirm this behaves as expected on your hardware/driver.',
            ];

            if ($isApEnd && $apMode === 'bridge') {
                $lines[] = '# mode=bridge is the wireless (RF) master mode -- not the same as a software "/interface bridge" (see below if this link is in bridged L3 mode).';
            }

            if ($isApEnd && $apMode === 'ap-bridge') {
                $lines[] = '# mode=ap-bridge (point-to-multipoint) needs a higher wireless license tier / AP-capable hardware -- a CPE-class radio (SXTsq, LHG, etc) will reject this.';
            }

            $lines[] = '';

            return array_merge($securityLines, $lines);
        }

        $wifiSecurityLines = $this->wifiSecurityLines($config);
        $configName = $isApEnd ? 'ptp-ap-cfg' : 'ptp-station-cfg';
        $mode = $isApEnd ? 'ap' : 'station';

        return array_merge($wifiSecurityLines, [
            '/interface wifi configuration add name='.$configName
                .' mode='.$mode
                .' ssid="'.$ssid.'"'
                .' security=ptp-sec'
                .' channel.frequency='.$config['frequency_mhz']
                .' channel.width='.$config['channel_width_mhz'].'mhz',
            // Same reasoning as the RouterOS 6 branch -- enable the interface as its
            // own command before assigning it a configuration, don't rely solely on
            // the inline disabled=no below.
            '/interface wifi enable [find default-name='.$interface.']',
            '/interface wifi set [find default-name='.$interface.'] configuration='.$configName
                .' configuration.distance='.$distanceMeters
                .' disabled=no',
            '# configuration.distance= auto-tunes ACK timeout for long links -- confirm this behaves as expected on your hardware/driver.',
            '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function legacySecurityLines(array $config): array
    {
        if ($config['security_mode'] === 'wpa2_wpa3') {
            return [
                '/interface wireless security-profiles add name=ptp-sec mode=dynamic-keys authentication-types=wpa2-psk wpa2-pre-shared-key="'.$this->quote($config['psk']).'"',
                '# RouterOS 6 legacy wireless has no WPA3 support -- falling back to WPA2-PSK only.',
            ];
        }

        return [
            '/interface wireless security-profiles add name=ptp-sec mode=dynamic-keys authentication-types=wpa2-psk wpa2-pre-shared-key="'.$this->quote($config['psk']).'"',
        ];
    }

    /**
     * @return list<string>
     */
    private function wifiSecurityLines(array $config): array
    {
        $authTypes = $config['security_mode'] === 'wpa2_wpa3' ? 'wpa2-psk,wpa3-psk' : 'wpa2-psk';

        return [
            '/interface wifi security add name=ptp-sec authentication-types='.$authTypes.' passphrase="'.$this->quote($config['psk']).'"',
        ];
    }

    /**
     * "Bridged" link mode needs an actual RouterOS bridge interface -- setting
     * the wireless mode to bridge/station-pseudobridge alone doesn't create
     * one. The wireless interface becomes a bridge port, and the PTP subnet's
     * IP address goes on the bridge (see addressingLines() call site). A
     * bridge with only the wireless interface as a member extends nothing --
     * `lan_port`, when given, joins a physical Ethernet port too so an actual
     * LAN device can plug in and reach across the link (the common case on
     * single-Ethernet-port CPE hardware like the SXTsq, where that one port
     * is the LAN connection, not a spare). Ethernet ports are enabled by
     * default out of the box, but `disabled=no` is set explicitly anyway --
     * cheap insurance against a port a previous config left disabled.
     * Routed mode needs none of this -- the address goes straight on the
     * wireless interface and each end stays its own subnet.
     *
     * @return list<string>
     */
    private function bridgeLines(array $config, array $radio): array
    {
        if ($config['link_mode'] !== 'bridged') {
            return [];
        }

        $lines = [
            '/interface bridge add name=bridge-ptp comment="PTP link bridge"',
            '/interface bridge port add bridge=bridge-ptp interface='.$radio['wireless_interface'],
        ];

        $lanPort = $radio['lan_port'] ?? null;

        if (filled($lanPort)) {
            $lines[] = '/interface ethernet set '.$lanPort.' disabled=no';
            $lines[] = '/interface bridge port add bridge=bridge-ptp interface='.$lanPort;
        } else {
            $lines[] = '# No LAN port configured -- add one to bridge-ptp (e.g. "/interface bridge port add bridge=bridge-ptp interface=ether1") so a LAN device can actually reach across this link.';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function addressingLines(string $ipCidr, string $interface): array
    {
        return [
            '/ip address add address='.$ipCidr.' interface='.$interface,
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function remoteRouteLines(array $radio, string $otherHost): array
    {
        if (! $radio['add_remote_route'] || blank($radio['remote_network'] ?? null)) {
            return [];
        }

        return [
            '/ip route add dst-address='.$radio['remote_network'].' gateway='.$otherHost.' comment="Route to the network behind the other end of this PTP link"',
            '',
        ];
    }

    /**
     * Plain CIDR math (own copy, not shared with StandaloneHotspotScriptGenerator's
     * networkParts() -- that one assumes a gateway-first /24 LAN input, this one
     * needs a real network-address + prefix-length subnet with as few as 2 usable
     * host addresses for a /30 point-to-point link).
     *
     * @return array{radio_a_ip: string, radio_b_ip: string, radio_a_host: string, radio_b_host: string}
     */
    private function ptpSubnetParts(string $cidr): array
    {
        [$address, $prefixRaw] = array_pad(explode('/', $cidr, 2), 2, '30');
        $prefix = (int) $prefixRaw;
        $mask = -1 << (32 - $prefix);
        $networkLong = ip2long($address) & $mask;

        $radioAHost = long2ip($networkLong + 1);
        $radioBHost = long2ip($networkLong + 2);

        return [
            'radio_a_ip' => $radioAHost.'/'.$prefix,
            'radio_b_ip' => $radioBHost.'/'.$prefix,
            'radio_a_host' => $radioAHost,
            'radio_b_host' => $radioBHost,
        ];
    }

    private function quote(?string $value): string
    {
        return str_replace('"', '\"', (string) $value);
    }
}
