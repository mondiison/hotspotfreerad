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
 *
 * `band`/`country`/`frequency_mode` (2026-08-08, from a real "bad band or
 * frequency" rejection on live hardware) are not cosmetic -- RouterOS 6
 * validates a requested `frequency=` against the *currently-configured*
 * `band`/`country`/`frequency-mode`, and a factory-default interface's
 * `country` (default `etsi`) plus an unset `band` can make an otherwise
 * perfectly valid frequency (e.g. 5745MHz, well inside a card's own
 * `4920-5925` hardware range per `/interface wireless info hw-info`) get
 * rejected outright. `band`/`country`/`frequency-mode` are set in the SAME
 * `/interface wireless set` command as `frequency=` (RouterOS validates a
 * `set`'s properties against the resulting state, not the prior one) --
 * confirmed against MikroTik's own documentation, not just inferred from the
 * error. `frequency-mode=regulatory-domain` (the default here) is what
 * actually enforces `country=`; `superchannel` bypasses it entirely (every
 * card-supported channel, no legal regulatory floor) and is offered as an
 * explicit opt-in only, flagged in-script as a compliance decision the
 * operator is making deliberately, not a default.
 *
 * `wireless_protocol=nv2` (RouterOS 6 legacy wireless only -- the RouterOS 7
 * `/interface wifi` package has no NV2 equivalent) is MikroTik's proprietary
 * TDMA-based link protocol: no CSMA hidden-node contention, no distance-based
 * throughput penalty the way standard 802.11 has, and it only works between
 * two MikroTik radios -- which is guaranteed here, since both ends of any
 * script this class generates are always MikroTik. Offered (not forced)
 * when both radios are RouterOS 6, since it's a genuine interop constraint:
 * an NV2 AP end refuses an 802.11-only station and vice versa.
 *
 * RouterOS 7's `/interface wifi` `station-bridge` mode (not plain `station`)
 * is what actually supports being added as a transparent bridge port -- per
 * MikroTik's own docs, `station-bridge` "enables support for a 4-address
 * frame format, so the interface can be used as a bridge port," which plain
 * `station` does not. `wirelessLines()` picks `station-bridge` for RouterOS
 * 7 stations only when `link_mode` is `'bridged'`, mirroring RouterOS 6's
 * existing `station`/`station-pseudobridge` split for the same L3 distinction.
 *
 * `antenna_gain_dbi` only emits `antenna-gain=` on RouterOS 6 -- confirmed
 * documented there ("used to calculate maximum transmit power according to
 * country regulations"), but the equivalent property path on RouterOS 7's
 * `/interface wifi` package could not be confirmed from available
 * documentation (it differs across the legacy `wifiwave2` and newer unified
 * `wifi` packages), so it's deliberately left unset there rather than
 * guessed -- the "don't guess unverified syntax" rule applied here means an
 * omission, not a wrong command.
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
     *     country: string,
     *     frequency_mode: string,
     *     wireless_protocol: string,
     *     hide_ssid: bool,
     *     skip_dfs_channels: bool,
     *     radio_a: array{identity: string, ros_version: string, wireless_interface: string, band: string, antenna_gain_dbi?: ?int, cpe_only?: bool, lan_port?: ?string, add_remote_route: bool, remote_network: ?string},
     *     radio_b: array{identity: string, ros_version: string, wireless_interface: string, band: string, antenna_gain_dbi?: ?int, cpe_only?: bool, lan_port?: ?string, add_remote_route: bool, remote_network: ?string},
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
        $country = $this->quote($config['country']);
        $hideSsid = $config['hide_ssid'] ? 'yes' : 'no';
        $stationMode = $config['link_mode'] === 'bridged' ? 'station-pseudobridge' : 'station';
        $distanceMeters = (int) round($config['distance_km'] * 1000);

        if ($radio['ros_version'] === '6') {
            $securityLines = $this->legacySecurityLines($config);
            $apMode = $config['link_topology'] === 'ptmp' ? 'ap-bridge' : 'bridge';
            $mode = $isApEnd ? $apMode : $stationMode;
            $useNv2 = $config['wireless_protocol'] === 'nv2';

            $setLine = '/interface wireless set '.$interface
                .' mode='.$mode
                .' band='.$radio['band']
                .' ssid="'.$ssid.'"'
                .' frequency='.$config['frequency_mhz']
                .' channel-width='.$config['channel_width_mhz'].'mhz'
                .' country="'.$country.'"'
                .' frequency-mode='.$config['frequency_mode']
                .($useNv2 ? ' wireless-protocol=nv2' : '')
                .' security-profile=ptp-sec'
                .' hide-ssid='.$hideSsid
                .' distance='.$distanceMeters;

            if (filled($radio['antenna_gain_dbi'] ?? null)) {
                $setLine .= ' antenna-gain='.$radio['antenna_gain_dbi'];
            }

            $setLine .= ' disabled=no';

            $lines = [
                // A factory-default wireless interface is administratively disabled,
                // and RouterOS 6 can reject mode=/frequency=/etc changes on a still-
                // disabled interface even when disabled=no is in the same `set`
                // command -- enable it as its own command first, don't rely on the
                // inline disabled=no below alone (kept anyway as a second safeguard).
                '/interface wireless enable '.$interface,
                $setLine,
                // band=/country=/frequency-mode= are set in the same command as
                // frequency= deliberately -- RouterOS validates a requested frequency
                // against the currently-configured band/country/frequency-mode, and a
                // factory-default interface (unset band, country=etsi) can reject an
                // otherwise-valid frequency as "bad band or frequency" if these aren't
                // set together. Run "/interface wireless info hw-info" on the actual
                // router first to confirm this band covers its real hardware range.
                '# distance= auto-tunes ACK timeout for long links -- confirm this behaves as expected on your hardware/driver.',
            ];

            if ($config['frequency_mode'] === 'superchannel') {
                $lines[] = '# frequency-mode=superchannel bypasses country regulatory limits entirely (every card-supported channel, no legal floor) -- only use this if you hold the appropriate spectrum licensing for this link.';
            }

            if ($useNv2) {
                $lines[] = '# wireless-protocol=nv2 only works between two MikroTik radios on RouterOS 6 legacy wireless -- both ends of this link must use it, never just one.';
            }

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
        $mode = match (true) {
            $isApEnd => 'ap',
            $config['link_mode'] === 'bridged' => 'station-bridge',
            default => 'station',
        };
        $skipDfs = $config['skip_dfs_channels'] ? 'all' : 'disabled';

        return array_merge($wifiSecurityLines, [
            '/interface wifi configuration add name='.$configName
                .' mode='.$mode
                .' ssid="'.$ssid.'"'
                .' security=ptp-sec'
                .' country="'.$country.'"'
                .' hide-ssid='.$hideSsid
                .' channel.band='.$radio['band']
                .' channel.frequency='.$config['frequency_mhz']
                .' channel.width='.$config['channel_width_mhz'].'mhz'
                .' channel.skip-dfs-channels='.$skipDfs,
            // Same reasoning as the RouterOS 6 branch -- enable the interface as its
            // own command before assigning it a configuration, don't rely solely on
            // the inline disabled=no below. country=/channel.band= are set on the
            // configuration object itself (not the interface) since that's where
            // RouterOS 7's wifi package applies regulatory-domain restrictions.
            '/interface wifi enable [find default-name='.$interface.']',
            '/interface wifi set [find default-name='.$interface.'] configuration='.$configName
                .' configuration.distance='.$distanceMeters
                .' disabled=no',
            '# configuration.distance= auto-tunes ACK timeout for long links -- confirm this behaves as expected on your hardware/driver.',
            '# antenna-gain is deliberately not set here -- its exact property path on RouterOS 7\'s wifi package could not be confirmed from documentation (it differs across the legacy wifiwave2 and newer unified wifi packages). Set it manually if your regulatory domain requires it.',
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
