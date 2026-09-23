<?php

namespace App\Services;

use App\Models\Router;
use App\Models\TrustedWifiDevice;
use App\Support\PaymentGatewayCatalog;

class MikroTikProvisioningService
{
    public function infrastructureProfiles(): array
    {
        return [
            'starlink_plaza' => [
                'name' => 'Starlink plaza / high concurrency',
                'summary' => 'RB5009-style core with VLANs, POS network, PCQ fairness, and realtime voice/video protection.',
                'capacity' => '100-500+ users with additional APs and WAN links',
            ],
            'small_hotspot' => [
                'name' => 'Small hotspot',
                'summary' => 'Simpler VLAN hotspot layout for cafes, small offices, and single-shop deployments.',
                'capacity' => '20-100 users',
            ],
            'pppoe_isp' => [
                'name' => 'PPPoE ISP access',
                'summary' => 'Subscriber VLAN and PPPoE server foundation for CPE-based customers.',
                'capacity' => 'Fixed subscribers with package bandwidth from RADIUS',
            ],
        ];
    }

    /**
     * The minimum a brand-new router needs pasted by hand: WireGuard
     * connectivity plus the RouterOS API user. Everything else (RADIUS
     * client, hotspot/PPPoE profiles, walled-garden, etc.) can then be
     * pushed live via RouterOsConnectionService::provisionHotspot()/
     * provisionPppoe() once this has run and the router is reachable over
     * the API -- there's no way to reach the API before this runs, since
     * the API user itself is created by these lines.
     */
    /**
     * Confirmed live 2026-09-21: this never set up WAN/internet access at all --
     * on a genuinely fresh/reset router (no default bridge or DHCP client left
     * over from the factory config) neither WireGuard nor ZeroTier had any path
     * out, so a router pasted with just this script alone could never actually
     * reach the Pi or ZeroTier's own root servers. Fixed by getting wan1 online
     * first, exactly the way generateFreshInfrastructureScript() already does,
     * mirrored here since bootstrap is meant to stand entirely on its own on a
     * blank router rather than assume the full script runs afterward -- DNS
     * included, not just the DHCP client/default route: `use-peer-dns=no` alone
     * leaves the router with no DNS at all, which matters for real whenever
     * services.wireguard.endpoint_host is a DDNS hostname rather than a raw IP
     * (a live report caught this missing the same day, right after the first
     * fix). The interface-list creation is guarded idempotently (unlike the
     * full script's unguarded version) since a router that gets the full script
     * pasted after this would otherwise hit a duplicate-list error re-running
     * it. Masquerade is deliberately NOT added here -- it only rewrites traffic
     * the router forwards on behalf of something else (LAN/hotspot clients),
     * and bootstrap has no client networks configured yet; every packet
     * bootstrap's own tunnels send already originates from the router itself.
     */
    public function generateBootstrapScript(Router $router, string $profile = 'starlink_plaza'): string
    {
        $settings = $this->provisioningSettings($router, $profile);
        $nasIdentifier = $router->nas_identifier;
        $wan1 = $settings['wan1'];
        $tunnelLines = implode("\n", array_merge($this->wireguardProvisioningLines($router), $this->zeroTierLines($router)));
        $apiUserLines = implode("\n", $this->apiUserProvisioningLines($router));

        return <<<SCRIPT
:global wan1 "{$wan1}"
/system identity set name="{$nasIdentifier}"
/ip dns set allow-remote-requests=yes servers=1.1.1.1,8.8.8.8
:if ([:len [/interface list find name=WAN]] = 0) do={ /interface list add name=WAN comment="Internet uplinks such as Starlink" }
:if ([:len [/interface list member find list=WAN interface=\$wan1]] = 0) do={ /interface list member add list=WAN interface=\$wan1 }
/ip dhcp-client remove [find interface=\$wan1]
/ip dhcp-client add interface=\$wan1 add-default-route=yes use-peer-dns=no disabled=no comment="Get WAN IP/default route so the tunnel below can actually dial out"
{$tunnelLines}
{$apiUserLines}
SCRIPT;
    }

    public function generateScript(Router $router): string
    {
        $router->loadMissing('shop');

        $nasIdentifier = $router->nas_identifier;
        $tunnelLines = implode("\n", array_merge($this->wireguardProvisioningLines($router), $this->zeroTierLines($router)));
        $apiUserLines = implode("\n", $this->apiUserProvisioningLines($router));
        $radiusLines = implode("\n", $this->radiusClientLines($router, 'hotspot,ppp'));
        $portalUrl = $this->portalUrl();
        $portalHost = parse_url($portalUrl, PHP_URL_HOST) ?: config('services.mikrotik.hotspot_dns_name');
        $hotspotDnsName = config('services.mikrotik.hotspot_dns_name');
        $walledGardenLines = implode("\n", $this->walledGardenLines($router, $portalHost));
        $htmlDirectory = $this->hotspotLoginDirectory($router);

        return <<<SCRIPT
/system identity set name="{$nasIdentifier}"
{$tunnelLines}
{$apiUserLines}
{$radiusLines}
/ip hotspot profile add name=saas-prof use-radius=yes login-by=http-pap,http-chap,cookie,mac-cookie html-directory={$htmlDirectory} dns-name={$hotspotDnsName}
/ip hotspot profile set saas-prof radius-accounting=yes
# Points any existing hotspot server at this profile. If none exists yet, this is a no-op --
# run "/ip hotspot setup" first (or select saas-prof as its profile), then re-run this line.
/ip hotspot set [find] profile=saas-prof
{$walledGardenLines}
SCRIPT;
    }

    /**
     * Confirmed live 2026-09-21 originally, and again 2026-09-23 from a
     * router (bebeji-router01) where enable_pos was flipped on *after* Fresh
     * Infrastructure Script had already been applied once: since that script
     * is explicitly not safe to re-run (duplicates everything else already
     * there), a router in that state had no VLAN/pool/DHCP for POS at all,
     * and this script's own `/ip hotspot add ... interface=vlan-pos` line
     * failed outright with "input does not match any value of interface" --
     * confirmed only recoverable by hand-deriving and pasting the missing
     * pieces individually. Fixed by making this script fully self-sufficient
     * -- it now creates the VLAN interface, virtual POS Wi-Fi (when built-in
     * Wi-Fi is enabled), any extra untagged POS ports, the bridge-vlan entry,
     * and the IP/pool/DHCP objects itself, duplicating the equivalent lines
     * in generateFreshInfrastructureScript()'s own POS section rather than
     * extracting a shared helper -- refactoring that heavily-tested method
     * carried more risk than the duplication does, and this codebase already
     * accepts this same tradeoff elsewhere (see the walled-garden-host note
     * in CLAUDE.md: "has to go in both places, they're not one shared source
     * of truth"). Like Fresh Infrastructure Script itself, this is NOT
     * idempotent -- do not paste it twice on a router that already has these
     * objects (e.g. one whose Fresh Infrastructure Script already included
     * POS), or it will create duplicates. Deliberately still does NOT add
     * its own `/radius` client line, unlike generateScript()/
     * generatePppoeScript() -- POS's hotspot server uses the "hotspot"
     * RADIUS service, which generateScript()'s own line (or the Bootstrap/
     * Fresh Infrastructure scripts) already covers, and RouterOS doesn't
     * dedupe `/radius add` entries on its own.
     */
    public function generatePosScript(Router $router, string $profile = 'starlink_plaza'): string
    {
        $nasIdentifier = $router->nas_identifier;
        $tunnelLines = implode("\n", array_merge($this->wireguardProvisioningLines($router), $this->zeroTierLines($router)));
        $apiUserLines = implode("\n", $this->apiUserProvisioningLines($router));
        $settings = $this->provisioningSettings($router, $profile);
        $lanBridgeName = 'bridge-lan';
        $taggedPorts = $lanBridgeName.','.$settings['trunk_port'];
        $enableBuiltinWifi = (bool) $settings['enable_builtin_wifi'];
        $extraPosPorts = $this->extraPortInterfaces($settings, 'extra_pos_ports');

        $wifiLines = $enableBuiltinWifi ? [
            '/interface wifi security add name=mms-pos-sec authentication-types=wpa2-psk,wpa3-psk passphrase="'.$this->quote($settings['pos_wifi_password']).'"',
            '/interface wifi configuration add name=mms-pos-cfg mode=ap ssid="'.$this->quote($settings['pos_ssid']).'" security=mms-pos-sec country=Nigeria',
            '/interface wifi add name=wifi-pos master-interface='.$settings['builtin_wifi_interface'].' configuration=mms-pos-cfg disabled=no',
            '/interface bridge port add bridge='.$lanBridgeName.' interface=wifi-pos pvid='.$settings['pos_vlan'].' comment="Virtual POS Wi-Fi for terminal testing"',
        ] : [];

        $extraPortLines = array_map(
            fn (string $port): string => '/interface bridge port add bridge='.$lanBridgeName.' interface='.$port.' pvid='.$settings['pos_vlan'].' comment="Extra POS access port"',
            $extraPosPorts
        );

        $untaggedMembers = implode(',', array_filter([$enableBuiltinWifi ? 'wifi-pos' : null, ...$extraPosPorts]));
        $bridgeVlanLine = $untaggedMembers !== ''
            ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.$untaggedMembers.' vlan-ids='.$settings['pos_vlan']
            : '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' vlan-ids='.$settings['pos_vlan'];

        $infraLines = implode("\n", array_merge(
            ['/interface vlan add interface='.$lanBridgeName.' name=vlan-pos vlan-id='.$settings['pos_vlan']],
            $wifiLines,
            $extraPortLines,
            [$bridgeVlanLine],
            [
                '/ip address add address='.$settings['pos_gateway'].' interface=vlan-pos comment="POS SSID VLAN, registered devices, no shared customer password"',
                '/ip pool add name=pool-pos ranges='.$settings['pos_pool'],
                '/ip dhcp-server add name=dhcp-pos interface=vlan-pos address-pool=pool-pos lease-time=12h disabled=no',
                '/ip dhcp-server network add address='.$settings['pos_network'].' gateway='.str($settings['pos_gateway'])->before('/').' dns-server='.str($settings['pos_gateway'])->before('/'),
            ]
        ));
        $macAuthPassword = $this->quote(RadiusProvisioningService::POS_MAC_AUTH_PASSWORD);

        return <<<SCRIPT
/system identity set name="{$nasIdentifier}"
{$tunnelLines}
{$apiUserLines}
# Creates the POS VLAN/Wi-Fi/ports/addressing -- NOT idempotent, same as the
# Fresh Infrastructure Script itself. Do not paste this twice, or if this
# router's Fresh Infrastructure Script has already included POS (enable_pos
# was already on when it was last generated/applied).
{$infraLines}
# Requires a RADIUS client for the "hotspot" service already added (Hotspot
# Script tab, or the Bootstrap/Fresh Infrastructure scripts) -- POS shares
# that RADIUS client, so this script does not add a second one.
# mac-auth-password is set explicitly rather than left blank -- confirmed
# live 2026-09-23 that RouterOS's own "defaults to the client's MAC" blank
# behavior doesn't actually send a CHAP-hashable password matching the MAC
# in radcheck, rejecting every MAC-auth attempt. This fixed value matches
# what RadiusProvisioningService::provisionPosDevice() now stores as every
# POS device's Cleartext-Password -- not a meaningful secret, since the MAC
# (the username) is what actually identifies the device.
/ip hotspot profile add name=mms-pos-profile use-radius=yes login-by=mac mac-auth-password="{$macAuthPassword}" radius-accounting=yes
/ip hotspot add name=mms-pos interface=vlan-pos address-pool=pool-pos profile=mms-pos-profile disabled=no
# Best-effort firewall rules -- the input-chain accept is placed before this
# router's own WAN-only catch-all input drop rule if one exists (confirmed
# live 2026-09-23 this is required: RouterOS evaluates filter rules in list
# order, and a plain `add` appends to the end, after that catch-all, where it
# would never be reached). Not yet confirmed this `place-before=[find ...]`
# syntax behaves safely on a router with no such catch-all rule at all.
/ip firewall address-list add list=mms-pos-subnets address={$settings['pos_network']}
/ip firewall filter add chain=input in-interface=vlan-pos protocol=tcp dst-port=80,443,64872-64875 action=accept comment="Allow POS MAC-auth hotspot services" place-before=[find action=drop in-interface-list=!WAN]
/ip firewall filter add chain=forward src-address={$settings['pos_network']} dst-address=10.0.0.0/8 action=drop comment="POS cannot reach private client/management networks"
SCRIPT;
    }

    /**
     * $pppoeInterface used to be a hardcoded generic "bridge1" placeholder
     * the admin had to manually retype before pasting -- while
     * generateFreshInfrastructureScript()'s own PPPoE section already
     * creates and binds the PPPoE server to a fixed "vlan-pppoe" interface
     * (see its own $pppoeLines). Changed to reference that same fixed name
     * instead, matching generatePosScript()'s "assumes the fresh
     * infrastructure script already created this VLAN" pattern -- so a
     * router that's already had Fresh Infrastructure Script applied needs
     * no manual edit here anymore. A router that never uses this app's own
     * VLAN scheme at all can still retype the interface, same as before.
     */
    public function generatePppoeScript(Router $router): string
    {
        $nasIdentifier = $router->nas_identifier;
        $tunnelLines = implode("\n", array_merge($this->wireguardProvisioningLines($router), $this->zeroTierLines($router)));
        $apiUserLines = implode("\n", $this->apiUserProvisioningLines($router));
        $radiusLines = implode("\n", $this->radiusClientLines($router, 'ppp'));
        $pppoeInterface = 'vlan-pppoe';

        return <<<SCRIPT
/system identity set name="{$nasIdentifier}"
{$tunnelLines}
{$apiUserLines}
{$radiusLines}
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
# PPPoE bandwidth is controlled by MMS Radius packages through Mikrotik-Rate-Limit.
# Keep this profile generic; do not hard-code rate-limit here unless you want a router-side override.
/ppp profile add name=mms-pppoe-profile only-one=yes change-tcp-mss=yes
# Requires the PPPoE VLAN already set up (Fresh Infrastructure Script's PPPoE section). If this router doesn't use that VLAN scheme, change {$pppoeInterface} to the correct subscriber VLAN or LAN bridge.
/interface pppoe-server server add interface={$pppoeInterface} service-name=mms-radius default-profile=mms-pppoe-profile authentication=pap,chap,mschap1,mschap2 disabled=no
SCRIPT;
    }

    /**
     * $trunkPort's bridge port is added with `frame-types=admit-only-vlan-tagged`
     * -- confirmed live 2026-09-19: without this, RouterOS still accepts
     * untagged frames on the port (via its default PVID=1, since nothing
     * else was ever set), and `vlan-filtering=yes` auto-creates a dynamic
     * VLAN 1 entry to keep that legal rather than rejecting it outright.
     * The trunk port is meant to carry ONLY tagged VLAN traffic from an
     * external AP/switch -- if that device's own VLAN config isn't actually
     * applied yet (a very real failure mode while bootstrapping a switch,
     * confirmed live), its still-untagged traffic would otherwise silently
     * land on VLAN 1 (which has no DHCP server, no L3 interface, nothing)
     * instead of being cleanly and visibly dropped, making "is my switch
     * sending tagged traffic yet?" much harder to diagnose than it needs to
     * be. This property makes RouterOS drop untagged frames on this port
     * outright, which is the actually-correct behavior for a pure trunk.
     */
    public function generateFreshInfrastructureScript(Router $router, string $profile = 'starlink_plaza'): string
    {
        $router->loadMissing('shop.tenant');

        $settings = $this->provisioningSettings($router, $profile);
        $profile = $settings['profile'];
        $routerIdentity = $this->quote($router->nas_identifier);
        $wgEndpoint = $this->wireguardEndpoint($router);
        $wgEndpointHost = $wgEndpoint['host'];
        $wgEndpointPort = $wgEndpoint['port'];
        $wgEndpointSource = $wgEndpoint['source'];
        $wgPublicKey = $this->quote(config('services.wireguard.public_key'));
        $portalUrl = $this->portalUrl();
        $portalHost = parse_url($portalUrl, PHP_URL_HOST) ?: config('services.mikrotik.hotspot_dns_name');
        $hotspotDnsName = config('services.mikrotik.hotspot_dns_name');
        $enableBuiltinWifi = (bool) $settings['enable_builtin_wifi'];
        $builtinWifiInterface = $settings['builtin_wifi_interface'];
        $staffWifiInterface = 'wifi-staff';
        $posWifiInterface = 'wifi-pos';
        $mgmtWifiInterface = 'wifi-mgmt';

        $allVlans = array_filter([
            $settings['mgmt_vlan'],
            $settings['hotspot_vlan'],
            $settings['enable_staff'] ? $settings['staff_vlan'] : null,
            $settings['enable_pppoe'] ? $settings['pppoe_vlan'] : null,
            $settings['enable_pos'] ? $settings['pos_vlan'] : null,
        ]);

        $lanBridgeName = 'bridge-lan';
        $taggedPorts = $lanBridgeName.','.$settings['trunk_port'];

        $extraMgmtPorts = $this->extraPortInterfaces($settings, 'extra_mgmt_ports');
        $extraHotspotPorts = $this->extraPortInterfaces($settings, 'extra_hotspot_ports');
        $extraStaffPorts = $settings['enable_staff'] ? $this->extraPortInterfaces($settings, 'extra_staff_ports') : [];
        $extraPosPorts = $settings['enable_pos'] ? $this->extraPortInterfaces($settings, 'extra_pos_ports') : [];

        // Every VLAN below normally rides tagged-only on the shared catch-all line (no
        // dedicated untagged member) -- pulling a VLAN's ID OUT of that shared line only
        // when it actually has extra untagged ports keeps this byte-identical to the old
        // output whenever no extras are configured, and avoids ever emitting the same
        // "vlan-ids=" value on two separate bridge-vlan-table lines in the same script.
        $taggedVlans = implode(',', array_filter($allVlans, function ($vlan) use ($settings, $extraHotspotPorts, $extraStaffPorts, $extraPosPorts): bool {
            if ((string) $vlan === (string) $settings['mgmt_vlan']) {
                return false;
            }
            if ($extraHotspotPorts !== [] && (string) $vlan === (string) $settings['hotspot_vlan']) {
                return false;
            }
            if ($extraStaffPorts !== [] && (string) $vlan === (string) $settings['staff_vlan']) {
                return false;
            }
            if ($extraPosPorts !== [] && (string) $vlan === (string) $settings['pos_vlan']) {
                return false;
            }

            return true;
        }));

        $mgmtUntagged = implode(',', array_filter([$settings['pi_port'], ...$extraMgmtPorts]));

        $bridgeVlanLines = $enableBuiltinWifi
            ? $this->builtinWifiBridgeVlanLines($settings, $lanBridgeName, $taggedPorts, $builtinWifiInterface, $staffWifiInterface, $posWifiInterface, $mgmtWifiInterface)
            : array_filter([
                '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.$mgmtUntagged.' vlan-ids='.$settings['mgmt_vlan'],
                $taggedVlans !== '' ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' vlan-ids='.$taggedVlans : null,
                $extraHotspotPorts !== [] ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.implode(',', $extraHotspotPorts).' vlan-ids='.$settings['hotspot_vlan'] : null,
                ($settings['enable_staff'] && $extraStaffPorts !== []) ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.implode(',', $extraStaffPorts).' vlan-ids='.$settings['staff_vlan'] : null,
                ($settings['enable_pos'] && $extraPosPorts !== []) ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.implode(',', $extraPosPorts).' vlan-ids='.$settings['pos_vlan'] : null,
            ]);

        $secondWanMember = $settings['enable_second_wan']
            ? '/interface list member add list=WAN interface=$wan2'
            : '# Add this when second Starlink is connected: /interface list member add list=WAN interface=$wan2';

        $secondWanNat = $settings['enable_second_wan']
            ? '/ip firewall nat add chain=srcnat out-interface=$wan2 action=masquerade'
            : '# Add this when second Starlink is connected: /ip firewall nat add chain=srcnat out-interface=$wan2 action=masquerade';

        $posLines = $settings['enable_pos'] ? [
            '',
            '/ip address add address=$posGateway interface=vlan-pos comment="POS SSID VLAN, registered devices, no shared customer password"',
            '/ip pool add name=pool-pos ranges=$posPool',
            '/ip dhcp-server add name=dhcp-pos interface=vlan-pos address-pool=pool-pos lease-time=12h disabled=no',
            '/ip dhcp-server network add address=$posNetwork gateway='.str($settings['pos_gateway'])->before('/').' dns-server='.str($settings['pos_gateway'])->before('/'),
            '# MAC-auth hotspot: only a MAC address registered as a POS device in MMS Radius',
            '# (and currently active/unexpired) is granted access past this VLAN, even though',
            '# every device on this SSID shares the same WPA2/WPA3 password to associate.',
            '# mac-auth-password is explicit, not left blank -- RouterOS\'s own "defaults to',
            '# the client\'s MAC" blank behavior does not send a password matching the MAC',
            '# in radcheck (confirmed live), rejecting every MAC-auth attempt.',
            '/ip hotspot profile add name=mms-pos-profile use-radius=yes login-by=mac mac-auth-password="'.$this->quote(RadiusProvisioningService::POS_MAC_AUTH_PASSWORD).'" radius-accounting=yes',
            '/ip hotspot add name=mms-pos interface=vlan-pos address-pool=pool-pos profile=mms-pos-profile disabled=no',
        ] : [
            '',
            '# POS VLAN is disabled for this router profile. Enable it when POS terminals need password Wi-Fi and app-managed renewal.',
        ];

        $staffLines = $settings['enable_staff'] ? [
            '',
            '/ip address add address=$staffGateway interface=vlan-staff comment="Password staff/admin SSID VLAN"',
            '/ip pool add name=pool-staff ranges=$staffPool',
            '/ip dhcp-server add name=dhcp-staff interface=vlan-staff address-pool=pool-staff lease-time=8h disabled=no',
            '/ip dhcp-server network add address=$staffNetwork gateway='.str($settings['staff_gateway'])->before('/').' dns-server='.str($settings['staff_gateway'])->before('/'),
        ] : [
            '',
            '# Staff VLAN/SSID is disabled for this router profile.',
        ];

        $pppoeLines = $settings['enable_pppoe'] ? [
            '',
            '/ip address add address=$pppoeGateway interface=vlan-pppoe comment="Optional PPPoE/CPE VLAN"',
            '/ppp aaa set use-radius=yes accounting=yes interim-update=5m',
            '/ppp profile add name=mms-pppoe-profile only-one=yes change-tcp-mss=yes',
            '/interface pppoe-server server add interface=vlan-pppoe service-name=mms-radius default-profile=mms-pppoe-profile authentication=pap,chap,mschap1,mschap2 disabled=no',
        ] : [
            '',
            '# PPPoE is disabled for this router profile. Enable it for CPE/subscriber deployments.',
        ];

        $qosLines = $settings['enable_realtime_qos'] ? [
            '',
            '/queue type add name=pcq-hotspot-down kind=pcq pcq-classifier=dst-address pcq-rate=0 pcq-limit=50KiB pcq-total-limit=4000KiB',
            '/queue type add name=pcq-hotspot-up kind=pcq pcq-classifier=src-address pcq-rate=0 pcq-limit=50KiB pcq-total-limit=4000KiB',
            '/ip firewall mangle add chain=prerouting src-address=$hotspotNetwork protocol=udp packet-size=0-600 action=mark-packet new-packet-mark=realtime-up passthrough=yes comment="Realtime voice/video small UDP upload"',
            '/ip firewall mangle add chain=postrouting dst-address=$hotspotNetwork protocol=udp packet-size=0-600 action=mark-packet new-packet-mark=realtime-down passthrough=yes comment="Realtime voice/video small UDP download"',
            '/ip firewall mangle add chain=prerouting src-address=$hotspotNetwork packet-mark=no-mark action=mark-packet new-packet-mark=hotspot-up passthrough=yes',
            '/ip firewall mangle add chain=postrouting dst-address=$hotspotNetwork packet-mark=no-mark action=mark-packet new-packet-mark=hotspot-down passthrough=yes',
            '/queue tree add name=mms-upload parent=$wan1 max-limit=$uploadLimit',
            '/queue tree add name=mms-download parent=vlan-hotspot max-limit=$downloadLimit',
            '/queue tree add name=realtime-upload parent=mms-upload packet-mark=realtime-up priority=1 limit-at=2M max-limit=$uploadLimit',
            '/queue tree add name=realtime-download parent=mms-download packet-mark=realtime-down priority=1 limit-at=5M max-limit=$downloadLimit',
            '/queue tree add name=hotspot-upload parent=mms-upload packet-mark=hotspot-up queue=pcq-hotspot-up priority=5 max-limit=$uploadLimit',
            '/queue tree add name=hotspot-download parent=mms-download packet-mark=hotspot-down queue=pcq-hotspot-down priority=5 max-limit=$downloadLimit',
            '# Disable FastTrack for hotspot traffic if a default firewall later adds it; FastTrack can bypass queues.',
            '',
            '/system script add name=mms-starlink-bandwidth-policy source=":local downNormal \"'.$settings['download_limit'].'\"; :local upNormal \"'.$settings['upload_limit'].'\"; /queue tree set [find name=mms-download] max-limit=\\$downNormal; /queue tree set [find name=mms-upload] max-limit=\\$upNormal;"',
            '/system scheduler add name=mms-refresh-bandwidth interval=10m on-event=mms-starlink-bandwidth-policy comment="Adjust parent PCQ limits here as Starlink capacity changes"',
        ] : [
            '',
            '# Realtime QoS and PCQ are disabled for this router profile.',
        ];

        $builtinWifiLines = $enableBuiltinWifi ? [
            '',
            '# MikroTik L009 built-in Wi-Fi test SSID. RouterOS v7 WiFi package uses wifi1 on L009UiGS-2HaxD.',
            '/interface wifi security add name=mms-open-hotspot-sec authentication-types=""',
            '/interface wifi configuration add name=mms-open-hotspot-cfg mode=ap ssid="'.$this->quote($settings['hotspot_ssid']).'" security=mms-open-hotspot-sec country=Nigeria',
            $settings['enable_staff'] ? '/interface wifi security add name=mms-staff-sec authentication-types=wpa2-psk,wpa3-psk passphrase="'.$this->quote($settings['staff_wifi_password']).'"' : '# Staff virtual Wi-Fi is disabled.',
            $settings['enable_pos'] ? '/interface wifi security add name=mms-pos-sec authentication-types=wpa2-psk,wpa3-psk passphrase="'.$this->quote($settings['pos_wifi_password']).'"' : '# POS virtual Wi-Fi security disabled.',
            $settings['enable_mgmt_wifi'] ? '/interface wifi security add name=mms-mgmt-sec authentication-types=wpa2-psk,wpa3-psk passphrase="'.$this->quote($settings['mgmt_wifi_password']).'"' : '# Management virtual Wi-Fi is disabled; wired Pi/management port remains active.',
            $settings['enable_staff'] ? '/interface wifi configuration add name=mms-staff-cfg mode=ap ssid="MMS Staff" security=mms-staff-sec country=Nigeria' : '# Staff virtual Wi-Fi configuration disabled.',
            $settings['enable_pos'] ? '/interface wifi configuration add name=mms-pos-cfg mode=ap ssid="'.$this->quote($settings['pos_ssid']).'" security=mms-pos-sec country=Nigeria' : '# POS virtual Wi-Fi configuration disabled.',
            $settings['enable_mgmt_wifi'] ? '/interface wifi configuration add name=mms-mgmt-cfg mode=ap ssid="MMS Mgmt" security=mms-mgmt-sec country=Nigeria' : '# Management virtual Wi-Fi configuration disabled.',
            '/interface wifi set [find default-name=$builtinWifiInterface] configuration=mms-open-hotspot-cfg disabled=no',
            $settings['enable_staff'] ? '/interface wifi add name=$staffWifiInterface master-interface=$builtinWifiInterface configuration=mms-staff-cfg disabled=no' : '# Staff virtual Wi-Fi interface disabled.',
            $settings['enable_pos'] ? '/interface wifi add name=$posWifiInterface master-interface=$builtinWifiInterface configuration=mms-pos-cfg disabled=no' : '# POS virtual Wi-Fi is disabled because POS VLAN is disabled.',
            $settings['enable_mgmt_wifi'] ? '/interface wifi add name=$mgmtWifiInterface master-interface=$builtinWifiInterface configuration=mms-mgmt-cfg disabled=no' : '# Management virtual Wi-Fi interface disabled.',
            '/interface bridge port add bridge=$lanBridge interface=$builtinWifiInterface pvid=$hotspotVlan comment="L009 built-in open hotspot Wi-Fi"',
            $settings['enable_staff'] ? '/interface bridge port add bridge=$lanBridge interface=$staffWifiInterface pvid=$staffVlan comment="Virtual staff/admin Wi-Fi"' : '# Staff virtual bridge port disabled.',
            $settings['enable_pos'] ? '/interface bridge port add bridge=$lanBridge interface=$posWifiInterface pvid=$posVlan comment="Virtual POS Wi-Fi for terminal testing"' : '# POS virtual bridge port disabled.',
            $settings['enable_mgmt_wifi'] ? '/interface bridge port add bridge=$lanBridge interface=$mgmtWifiInterface pvid=$mgmtVlan comment="Virtual management Wi-Fi for lab testing"' : '# Management virtual bridge port disabled.',
            ...($settings['enable_staff'] ? $this->wifiAccessListLines($router, '$staffWifiInterface', 'MMS Staff', TrustedWifiDevice::NETWORK_STAFF) : []),
            ...($settings['enable_mgmt_wifi'] ? $this->wifiAccessListLines($router, '$mgmtWifiInterface', 'MMS Mgmt', TrustedWifiDevice::NETWORK_MGMT) : []),
        ] : [
            '',
            '# Built-in MikroTik Wi-Fi is disabled for this profile. Use the AP/switch trunk for external APs.',
        ];

        return implode("\n", array_merge([
            '# MMS Radius flexible MikroTik infrastructure script',
            '# Profile: '.$this->infrastructureProfiles()[$profile]['name'],
            '# Use on a fresh/no-default-config router, or review each section before pasting on an existing router.',
            $this->includesWireguard($router)
                ? '# WireGuard endpoint: '.$wgEndpointHost.':'.$wgEndpointPort.' ('.$wgEndpointSource.'). If this router is on the same LAN as the Pi, this must be the Pi LAN IP from hostname -I.'
                : '# ZeroTier-only tunnel mode -- no WireGuard endpoint for this router.',
            '# Edit these global values first to match the tenant hardware and cabling.',
            '# Global variables are used so this works when pasted directly into RouterOS terminal.',
            ':global wan1 "'.$settings['wan1'].'"',
            ':global wan2 "'.$settings['wan2'].'"',
            ':global lanBridge "bridge-lan"',
            ':global trunkPort "'.$settings['trunk_port'].'"',
            ':global piPort "'.$settings['pi_port'].'"',
            ':global builtinWifiInterface "'.$builtinWifiInterface.'"',
            ':global staffWifiInterface "'.$staffWifiInterface.'"',
            ':global posWifiInterface "'.$posWifiInterface.'"',
            ':global mgmtWifiInterface "'.$mgmtWifiInterface.'"',
            ':global mgmtVlan "'.$settings['mgmt_vlan'].'"',
            ':global hotspotVlan "'.$settings['hotspot_vlan'].'"',
            ':global staffVlan "'.$settings['staff_vlan'].'"',
            ':global pppoeVlan "'.$settings['pppoe_vlan'].'"',
            ':global posVlan "'.$settings['pos_vlan'].'"',
            ':global mgmtGateway "'.$settings['mgmt_gateway'].'"',
            ':global mgmtNetwork "'.$settings['mgmt_network'].'"',
            ':global mgmtPool "'.$settings['mgmt_pool'].'"',
            ':global hotspotGateway "'.$settings['hotspot_gateway'].'"',
            ':global hotspotNetwork "'.$settings['hotspot_network'].'"',
            ':global hotspotPool "'.$settings['hotspot_pool'].'"',
            ':global staffGateway "'.$settings['staff_gateway'].'"',
            ':global staffNetwork "'.$settings['staff_network'].'"',
            ':global staffPool "'.$settings['staff_pool'].'"',
            ':global posGateway "'.$settings['pos_gateway'].'"',
            ':global posNetwork "'.$settings['pos_network'].'"',
            ':global posPool "'.$settings['pos_pool'].'"',
            ':global pppoeGateway "'.$settings['pppoe_gateway'].'"',
            ':global downloadLimit "'.$settings['download_limit'].'"',
            ':global uploadLimit "'.$settings['upload_limit'].'"',
            '',
            '/system identity set name="'.$routerIdentity.'"',
            '/ip dns set allow-remote-requests=yes servers=1.1.1.1,8.8.8.8',
            '/interface list add name=WAN comment="Internet uplinks such as Starlink"',
            '/interface list member add list=WAN interface=$wan1',
            $secondWanMember,
            '/ip dhcp-client remove [find interface=$wan1]',
            '/ip dhcp-client add interface=$wan1 add-default-route=yes use-peer-dns=no disabled=no comment="Get WAN IP/default route from Starlink or ISP router"',
            '/interface bridge add name=$lanBridge protocol-mode=rstp vlan-filtering=no comment="MMS Radius LAN bridge"',
            '/interface bridge port add bridge=$lanBridge interface=$trunkPort frame-types=admit-only-vlan-tagged comment="AP/switch trunk carrying MMS Radius VLANs -- tagged only, untagged frames dropped"',
            '/interface bridge port add bridge=$lanBridge interface=$piPort pvid=$mgmtVlan comment="Pi/management access port, untagged VLAN 10 by default"',
            ...array_map(fn (string $p): string => '/interface bridge port add bridge=$lanBridge interface='.$p.' pvid=$mgmtVlan comment="Extra management access port"', $extraMgmtPorts),
            ...array_map(fn (string $p): string => '/interface bridge port add bridge=$lanBridge interface='.$p.' pvid=$hotspotVlan comment="Extra hotspot access port"', $extraHotspotPorts),
            ...array_map(fn (string $p): string => '/interface bridge port add bridge=$lanBridge interface='.$p.' pvid=$staffVlan comment="Extra staff access port"', $extraStaffPorts),
            ...array_map(fn (string $p): string => '/interface bridge port add bridge=$lanBridge interface='.$p.' pvid=$posVlan comment="Extra POS access port"', $extraPosPorts),
            '/interface vlan add interface=$lanBridge name=vlan-mgmt vlan-id=$mgmtVlan',
            '/interface vlan add interface=$lanBridge name=vlan-hotspot vlan-id=$hotspotVlan',
            $settings['enable_staff'] ? '/interface vlan add interface=$lanBridge name=vlan-staff vlan-id=$staffVlan' : '# Staff VLAN interface disabled',
            $settings['enable_pppoe'] ? '/interface vlan add interface=$lanBridge name=vlan-pppoe vlan-id=$pppoeVlan' : '# PPPoE VLAN interface disabled',
            $settings['enable_pos'] ? '/interface vlan add interface=$lanBridge name=vlan-pos vlan-id=$posVlan' : '# POS VLAN interface disabled',
        ], $builtinWifiLines, $bridgeVlanLines, [
            '/interface bridge set $lanBridge vlan-filtering=yes',
            '',
            ...($this->includesWireguard($router) ? [
                $this->wireguardInterfaceLine($router),
                '/interface wireguard peers add interface=wg-saas public-key="'.$wgPublicKey.'" endpoint-address='.$wgEndpointHost.' endpoint-port='.$wgEndpointPort.' allowed-address=10.8.0.1/32 persistent-keepalive=25s',
                '/ip address add address='.$router->wireguard_internal_ip.'/24 interface=wg-saas comment="MMS Radius WireGuard IP"',
            ] : []),
            ...$this->zeroTierLines($router),
            ...$this->apiUserProvisioningLines($router),
            ...$this->radiusClientLines($router, 'hotspot,ppp'),
            '',
            '/ip address add address=$mgmtGateway interface=vlan-mgmt comment="Management VLAN for Pi, router, AP, and switch administration"',
            '/ip pool add name=pool-mgmt ranges=$mgmtPool',
            '/ip dhcp-server add name=dhcp-mgmt interface=vlan-mgmt address-pool=pool-mgmt lease-time=8h disabled=no',
            '/ip dhcp-server network add address=$mgmtNetwork gateway='.str($settings['mgmt_gateway'])->before('/').' dns-server='.str($settings['mgmt_gateway'])->before('/'),
            '# Connect the Raspberry Pi to $piPort. It should receive an address from $mgmtPool.',
            '',
            '/ip address add address=$hotspotGateway interface=vlan-hotspot comment="Open customer hotspot VLAN"',
            '/ip pool add name=pool-hotspot ranges=$hotspotPool',
            '/ip dhcp-server add name=dhcp-hotspot interface=vlan-hotspot address-pool=pool-hotspot lease-time=30m disabled=no',
            '/ip dhcp-server network add address=$hotspotNetwork gateway='.str($settings['hotspot_gateway'])->before('/').' dns-server='.str($settings['hotspot_gateway'])->before('/'),
            '# DHCP for MMS Hotspot is served from vlan-hotspot. Do not attach hotspot DHCP directly to wifi1/ether ports because bridge member ports become slave interfaces.',
            '/ip hotspot profile add name=mms-hotspot-profile use-radius=yes login-by=http-pap,http-chap,cookie,mac-cookie html-directory='.$this->hotspotLoginDirectory($router).' dns-name='.$hotspotDnsName.' radius-accounting=yes',
            '/ip hotspot add name=mms-hotspot interface=vlan-hotspot address-pool=pool-hotspot profile=mms-hotspot-profile disabled=no',
            ...$this->walledGardenLines($router, $portalHost),
        ], $staffLines, $posLines, $pppoeLines, [
            '',
            '# /ip firewall filter only ever sees IP traffic -- MAC-level access (Winbox\'s',
            '# "Neighbors" discovery, and MAC-Telnet) is a separate Layer 2 mechanism that',
            '# bypasses the IP firewall entirely and is wide open on every interface by',
            '# default. Confirmed live: without this, a laptop on a hotspot/other non-mgmt',
            '# port could still reach this router over Winbox via MAC address, regardless of',
            '# any /ip firewall filter rule below. Restrict it to the same physical ports',
            '# actually carrying management traffic.',
            '/interface list add name=MGMT-ACCESS comment="Physical ports allowed to MAC-Winbox/MAC-Telnet into this router"',
            '/interface list member add list=MGMT-ACCESS interface=$piPort',
            ...array_map(fn (string $p): string => '/interface list member add list=MGMT-ACCESS interface='.$p, $extraMgmtPorts),
            '/tool mac-server set allowed-interface-list=MGMT-ACCESS',
            '/tool mac-server mac-winbox set allowed-interface-list=MGMT-ACCESS',
            '',
            '/ip firewall address-list add list=mms-hotspot-subnets address=$hotspotNetwork',
            $settings['enable_pos'] ? '/ip firewall address-list add list=mms-pos-subnets address=$posNetwork' : '# POS firewall list disabled',
            // Confirmed live 2026-09-21: this always accepted "wg-saas" but never had a
            // ZeroTier equivalent, so a ZeroTier-only router applying this script cut off
            // its own RouterOS API access -- the catch-all "drop everything not WAN" rule
            // below silently blocked the Pi's incoming connection on zerotier1, while the
            // router's own outbound traffic (e.g. pinging the Pi) was unaffected since that's
            // the forward/output path, not this input chain. Ping-to-Pi succeeding is NOT
            // evidence the API is reachable -- confirmed the hard way on a live router.
            '/ip firewall filter add chain=input connection-state=established,related action=accept',
            '/ip firewall filter add chain=input connection-state=invalid action=drop',
            $this->includesWireguard($router) ? '/ip firewall filter add chain=input in-interface=wg-saas action=accept comment="Allow MMS Radius tunnel (WireGuard)"' : '# WireGuard tunnel input rule disabled -- this router does not use WireGuard',
            $this->includesZeroTier($router) ? '/ip firewall filter add chain=input in-interface=zerotier1 action=accept comment="Allow MMS Radius tunnel (ZeroTier)"' : '# ZeroTier tunnel input rule disabled -- this router does not use ZeroTier',
            '/ip firewall filter add chain=input in-interface=vlan-mgmt action=accept comment="Allow management VLAN to router"',
            '/ip firewall filter add chain=input protocol=udp dst-port=53,67 action=accept comment="Allow DNS/DHCP from client VLANs"',
            '/ip firewall filter add chain=input in-interface=vlan-hotspot protocol=tcp dst-port=80,443,64872-64875 action=accept comment="Allow hotspot captive portal services"',
            $settings['enable_pos'] ? '/ip firewall filter add chain=input in-interface=vlan-pos protocol=tcp dst-port=80,443,64872-64875 action=accept comment="Allow POS MAC-auth hotspot services"' : '# POS hotspot input rule disabled',
            '/ip firewall filter add chain=input in-interface-list=!WAN action=drop comment="Drop other router access from clients"',
            '/ip firewall filter add chain=forward connection-state=established,related action=accept',
            '/ip firewall filter add chain=forward connection-state=invalid action=drop',
            $settings['enable_pos'] ? '/ip firewall filter add chain=forward src-address=$posNetwork dst-address=10.0.0.0/8 action=drop comment="POS cannot reach private client/management networks"' : '# POS isolation disabled',
            '/ip firewall filter add chain=forward src-address=$hotspotNetwork dst-address=192.168.0.0/16 action=drop comment="Hotspot clients cannot reach LAN/private networks"',
            '/ip firewall nat add chain=srcnat out-interface=$wan1 action=masquerade',
            $secondWanNat,
        ], $qosLines, [
            '',
            '# AP SSID mapping recommended by MMS Radius:',
            '# '.$settings['hotspot_ssid'].' = open SSID tagged VLAN '.$settings['hotspot_vlan'].', captive portal',
            $settings['enable_staff'] ? '# MMS Staff = WPA2/WPA3 SSID tagged VLAN '.$settings['staff_vlan'] : '# MMS Staff disabled',
            $settings['enable_pos'] ? '# '.$settings['pos_ssid'].' = WPA2/WPA3 SSID tagged VLAN '.$settings['pos_vlan'].', hidden optional, registered devices' : '# MMS POS disabled',
            $settings['enable_mgmt_wifi'] ? '# MMS Mgmt = restricted SSID tagged VLAN '.$settings['mgmt_vlan'] : '# MMS Mgmt Wi-Fi disabled; wired management VLAN remains on the Pi port',
        ]));
    }

    public function generateAccessPointGuide(): string
    {
        return implode("\n", [
            'Recommended SSID and VLAN plan',
            '',
            'MMS Hotspot',
            '- Security: Open',
            '- VLAN: 20',
            '- Purpose: customer captive portal access',
            '',
            'MMS Staff',
            '- Security: WPA2/WPA3 password',
            '- VLAN: 30',
            '- Purpose: tenant/admin devices',
            '',
            'MMS POS',
            '- Security: WPA2/WPA3 password',
            '- VLAN: 50',
            '- Purpose: POS terminals registered in MMS Radius',
            '- Hidden SSID: optional, not a security replacement',
            '',
            'MMS Mgmt',
            '- Security: strong WPA2/WPA3 or wired-only',
            '- VLAN: 10',
            '- Purpose: router, AP, switch, and Pi management',
            '',
            'Use Ruijie/Omada-style APs for multi-SSID VLAN zones. Use Wavlink for extra coverage only unless the exact model supports VLAN-per-SSID tagging.',
        ]);
    }

    public function generateLoginTemplate(): string
    {
        $portalUrl = $this->portalUrl();

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opening hotspot portal</title>
</head>
<body style="font-family: system-ui, sans-serif; padding: 24px;">
    <h1>Opening internet access</h1>
    <p>If nothing happens, use the button below.</p>
    <p><a id="portal-link" href="#">Continue to internet packages</a></p>

    <script>
        var portal = '{$portalUrl}'
            + '?mac=' + encodeURIComponent('\$(mac)')
            + '&nasid=' + encodeURIComponent('\$(identity)')
            + '&link-login=' + encodeURIComponent('\$(link-login)')
            + '&link-login-only=' + encodeURIComponent('\$(link-login-only)')
            + '&link-orig=' + encodeURIComponent('\$(link-orig)');

        document.getElementById('portal-link').href = portal;
        window.location.replace(portal);
    </script>
</body>
</html>
HTML;
    }

    public function portalUrl(): string
    {
        $configuredUrl = config('services.mikrotik.portal_url');

        return $configuredUrl
            ? rtrim((string) $configuredUrl, '/')
            : rtrim(config('app.url'), '/').'/hotspot/portal';
    }

    /**
     * The URL a router fetches (via `/tool fetch` -- see
     * RouterOsConnectionService::pushHotspotLoginPage()) to replace its
     * default local hotspot login page with the redirect stub below.
     * Built from the same host as portalUrl() rather than Laravel's own
     * route()/APP_URL, so it stays correct if HOTSPOT_PORTAL_URL is
     * configured to a different public host than APP_URL.
     */
    public function loginPageUrl(): string
    {
        $portalUrl = $this->portalUrl();
        $host = parse_url($portalUrl, PHP_URL_HOST) ?: config('services.mikrotik.hotspot_dns_name');
        $scheme = parse_url($portalUrl, PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.$host.'/hotspot/login-page';
    }

    /**
     * The router's local flash/hotspot login.html is otherwise MikroTik's
     * stock form, which would let customers "log in" against RouterOS's own
     * hotspot server directly -- never reaching this app's portal, payment
     * gateways, or RADIUS provisioning at all. RouterOS doesn't redirect
     * hotspot logins to an external URL on its own; the html-directory file
     * has to do that itself. This is a minimal stub that carries MikroTik's
     * own login-time variables ($(mac)/$(identity)/$(link-login)/
     * $(link-login-only)/$(link-orig) -- substituted by RouterOS when it
     * serves the file, not by this app) straight into the real portal URL.
     * It has no per-router content -- every router fetches the exact same
     * file, since there's only one portal host per install -- see
     * loginPageUrl()/pushHotspotLoginPage().
     *
     * $(link-login-only) is the critical one, confirmed live 2026-09-22 as
     * the actual root cause of a customer-facing redirect loop: this stub
     * never captured it at all (an oversight since this file was first
     * written -- neither the code nor this docblock ever mentioned it), so
     * PortalController::mikrotikLoginUrl()'s own preference for
     * link-login-only over link-login (it checks link-login-only first) was
     * never actually reachable -- every login attempt fell back to
     * link-login, MikroTik's standard interactive login endpoint, which
     * expects the router's own CHAP-challenge page flow rather than a
     * directly-submitted plain username/password. link-login-only is
     * MikroTik's documented endpoint specifically for external/automated
     * login pages like this one, accepting a plain username/password
     * directly with no challenge handshake required -- exactly what
     * hotspot.access-granted.blade.php submits.
     */
    public function hotspotLoginPageHtml(): string
    {
        $portal = $this->portalUrl();

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opening hotspot portal</title>
</head>
<body style="font-family: system-ui, sans-serif; padding: 24px;">
    <h1>Opening internet access</h1>
    <p>If nothing happens, use the button below.</p>
    <p><a id="portal-link" href="#">Continue to internet packages</a></p>

    <script>
        var portal = '{$portal}'
            + '?mac=' + encodeURIComponent('\$(mac)')
            + '&nasid=' + encodeURIComponent('\$(identity)')
            + '&link-login=' + encodeURIComponent('\$(link-login)')
            + '&link-login-only=' + encodeURIComponent('\$(link-login-only)')
            + '&link-orig=' + encodeURIComponent('\$(link-orig)');

        document.getElementById('portal-link').href = portal;
        window.location.replace(portal);
    </script>
</body>
</html>
HTML;
    }

    private function profileDefaults(string $profile): array
    {
        return match ($profile) {
            'small_hotspot' => [
                'wan1' => 'ether1',
                'wan2' => 'ether8',
                'trunk_port' => 'ether2',
                'download_limit' => '80M',
                'upload_limit' => '15M',
            ],
            'pppoe_isp' => [
                'wan1' => 'ether1',
                'wan2' => 'ether8',
                'trunk_port' => 'ether2',
                'download_limit' => '150M',
                'upload_limit' => '25M',
            ],
            default => [
                'wan1' => 'ether1',
                'wan2' => 'ether8',
                'trunk_port' => 'ether2',
                'download_limit' => '120M',
                'upload_limit' => '20M',
            ],
        };
    }

    /**
     * Public so RouterOsConnectionService::ensurePosInfrastructure() can read
     * the exact same defaulted pos_vlan/pos_gateway/pos_pool/pos_network
     * values generatePosScript() itself uses, rather than a second, narrower
     * copy of these defaults that could drift from this one -- the same
     * "two places must not disagree" concern that's bitten this codebase
     * before (see the SSID-defaults-array story elsewhere in CLAUDE.md).
     */
    public function provisioningSettings(Router $router, string $profile): array
    {
        $settings = array_filter(
            (array) $router->provisioning_settings,
            fn ($value): bool => $value !== null && $value !== ''
        );
        $profile = (string) ($settings['profile'] ?? $profile);

        $defaults = [
            'profile' => array_key_exists($profile, $this->infrastructureProfiles()) ? $profile : 'starlink_plaza',
            'wan1' => $this->profileDefaults($profile)['wan1'],
            'wan2' => $this->profileDefaults($profile)['wan2'],
            'trunk_port' => $this->profileDefaults($profile)['trunk_port'],
            'builtin_wifi_interface' => 'wifi1',
            'pi_port' => 'ether3',
            'hotspot_ssid' => 'MMS Hotspot',
            'pos_ssid' => 'MMS POS',
            'staff_wifi_password' => 'MmsStaff2026!',
            'pos_wifi_password' => 'MmsPos2026!',
            'mgmt_wifi_password' => 'MmsMgmt2026!',
            'download_limit' => $this->profileDefaults($profile)['download_limit'],
            'upload_limit' => $this->profileDefaults($profile)['upload_limit'],
            'mgmt_vlan' => 10,
            'hotspot_vlan' => 20,
            'staff_vlan' => 30,
            'pppoe_vlan' => 40,
            'pos_vlan' => 50,
            'mgmt_gateway' => '192.168.10.1/24',
            'mgmt_network' => '192.168.10.0/24',
            'mgmt_pool' => '192.168.10.10-192.168.10.250',
            'hotspot_gateway' => '10.5.50.1/23',
            'hotspot_network' => '10.5.50.0/23',
            'hotspot_pool' => '10.5.50.10-10.5.51.250',
            'staff_gateway' => '192.168.30.1/24',
            'staff_network' => '192.168.30.0/24',
            'staff_pool' => '192.168.30.10-192.168.30.250',
            'pos_gateway' => '192.168.50.1/24',
            'pos_network' => '192.168.50.0/24',
            'pos_pool' => '192.168.50.10-192.168.50.250',
            'pppoe_gateway' => '172.16.40.1/24',
            'enable_builtin_wifi' => false,
            'enable_staff' => true,
            'enable_mgmt_wifi' => false,
            'enable_pos' => true,
            'enable_pppoe' => $profile !== 'small_hotspot',
            'enable_realtime_qos' => true,
            'enable_second_wan' => false,
        ];

        return array_replace($defaults, $settings);
    }

    private function builtinWifiBridgeVlanLines(array $settings, string $lanBridgeName, string $taggedPorts, string $hotspotWifiInterface, string $staffWifiInterface, string $posWifiInterface, string $mgmtWifiInterface): array
    {
        $mgmtUntagged = implode(',', array_filter([
            $settings['pi_port'],
            $settings['enable_mgmt_wifi'] ? $mgmtWifiInterface : null,
            ...$this->extraPortInterfaces($settings, 'extra_mgmt_ports'),
        ]));
        $hotspotUntagged = implode(',', array_filter([$hotspotWifiInterface, ...$this->extraPortInterfaces($settings, 'extra_hotspot_ports')]));
        $staffUntagged = implode(',', array_filter([$staffWifiInterface, ...$this->extraPortInterfaces($settings, 'extra_staff_ports')]));
        $posUntagged = implode(',', array_filter([$posWifiInterface, ...$this->extraPortInterfaces($settings, 'extra_pos_ports')]));
        $pppoeTaggedOnly = $settings['enable_pppoe']
            ? $this->taggedVlanLine($lanBridgeName, $taggedPorts, $settings['pppoe_vlan'])
            : null;

        return array_filter([
            '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.$mgmtUntagged.' vlan-ids='.$settings['mgmt_vlan'],
            '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.$hotspotUntagged.' vlan-ids='.$settings['hotspot_vlan'],
            $settings['enable_staff'] ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.$staffUntagged.' vlan-ids='.$settings['staff_vlan'] : null,
            $settings['enable_pos'] ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.$posUntagged.' vlan-ids='.$settings['pos_vlan'] : null,
            $settings['enable_pppoe'] ? $pppoeTaggedOnly : null,
            '# If your AP/switch also needs tagged Staff/PPPoE/POS VLANs, keep the tagged ports above and use these virtual SSIDs only for MikroTik built-in Wi-Fi testing.',
        ]);
    }

    private function taggedVlanLine(string $lanBridgeName, string $taggedPorts, int|string $vlan): string
    {
        return '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' vlan-ids='.$vlan;
    }

    /**
     * The interface names in a comma-separated "extra ports" string
     * (e.g. "ether5,ether6,ether7") -- trims and drops empty entries. Needs
     * no awareness of picker vs. advanced mode: RouterManagementService's
     * derivePortInterfaceNames() has already normalized $settings[$key]
     * into this shape by the time this service ever sees it, exactly like
     * the singular trunk_port/pi_port fields already work.
     *
     * @return list<string>
     */
    private function extraPortInterfaces(array $settings, string $key): array
    {
        return collect(explode(',', (string) ($settings[$key] ?? '')))
            ->map(fn (string $piece): string => trim($piece))
            ->filter(fn (string $piece): bool => $piece !== '')
            ->values()
            ->all();
    }

    private function quote(?string $value): string
    {
        return str_replace('"', '\"', (string) $value);
    }

    /**
     * @return list<string>
     */
    private function wifiAccessListLines(Router $router, string $interfaceVariable, string $ssidLabel, string $network): array
    {
        $devices = TrustedWifiDevice::query()
            ->where('shop_id', $router->shop_id)
            ->where('network', $network)
            ->get()
            ->filter(fn (TrustedWifiDevice $device): bool => $device->isCurrentlyActive());

        if ($devices->isEmpty()) {
            return [
                "# No trusted {$ssidLabel} devices registered in MMS Radius yet, so this SSID currently accepts anyone with the password.",
                "# Register devices under Trusted Wi-Fi Devices, then regenerate this script to restrict {$ssidLabel} to only those MAC addresses.",
            ];
        }

        $lines = [
            "# Only these MMS Radius-registered devices may join {$ssidLabel}, even with the correct password.",
            "# Verify access-list behavior on your exact RouterOS/wifi-package version before relying on it in production.",
        ];

        foreach ($devices as $device) {
            $comment = trim($device->device_name.($device->owner_name ? ' ('.$device->owner_name.')' : ''));
            $lines[] = '/interface wifi access-list add interface='.$interfaceVariable.' mac-address='.$device->mac_address.' action=accept comment="'.$this->quote($comment).'"';
        }

        $lines[] = '/interface wifi access-list add interface='.$interfaceVariable.' action=reject comment="Default-deny: only registered '.$ssidLabel.' devices may join"';

        return $lines;
    }

    private function wireguardInterfaceLine(Router $router): string
    {
        $privateKey = $router->wireguard_private_key;

        return filled($privateKey)
            ? '/interface wireguard add name=wg-saas listen-port=13231 mtu=1420 private-key="'.$this->quote($privateKey).'"'
            : '/interface wireguard add name=wg-saas listen-port=13231 mtu=1420';
    }

    /**
     * Whether this router's tunnel_mode expects a working WireGuard link at
     * all -- a `zerotier`-only router has no business dialing an endpoint
     * that can never handshake, so its script omits the WireGuard block
     * entirely rather than leaving it present-but-unused.
     */
    private function includesWireguard(Router $router): bool
    {
        return in_array($router->tunnel_mode, ['wireguard', 'wireguard_zerotier'], true);
    }

    /**
     * Whether this router's tunnel_mode includes the ZeroTier fallback.
     */
    private function includesZeroTier(Router $router): bool
    {
        return in_array($router->tunnel_mode, ['wireguard_zerotier', 'zerotier'], true);
    }

    /**
     * The 4-line WireGuard interface/peer/address block shared identically
     * across generateBootstrapScript()/generateScript()/generatePppoeScript()
     * -- empty when includesWireguard() is false.
     *
     * @return list<string>
     */
    private function wireguardProvisioningLines(Router $router): array
    {
        if (! $this->includesWireguard($router)) {
            return [];
        }

        $wgEndpoint = $this->wireguardEndpoint($router);
        $wgPublicKey = config('services.wireguard.public_key');

        return [
            '# WireGuard endpoint: '.$wgEndpoint['host'].':'.$wgEndpoint['port'].' ('.$wgEndpoint['source'].'). For a router on the same LAN as the Pi, this must be the Pi\'s current LAN IP.',
            $this->wireguardInterfaceLine($router),
            '/interface wireguard peers add interface=wg-saas public-key="'.$wgPublicKey.'" endpoint-address='.$wgEndpoint['host'].' endpoint-port='.$wgEndpoint['port'].' allowed-address=10.8.0.1/32 persistent-keepalive=25s',
            '/ip address add address='.$router->wireguard_internal_ip.'/24 interface=wg-saas',
        ];
    }

    /**
     * RouterOS has no native ZeroTier support before 7.5, and even then it's
     * a separate installable package restricted to ARM/ARM64 hardware --
     * this is a per-router opt-in (tunnel_mode), never assumed available.
     * The network joined here is a self-hosted controller on the Pi, not
     * ZeroTier Central -- see docs/zerotier-fallback-setup.md for why
     * (Central's free plan has no API access at all). A router's ZeroTier
     * node identity is generated locally the first time these lines run;
     * this app has no way to predict it in advance the way it does for
     * WireGuard keys, so the resulting node ID has to be read back
     * (`/zerotier print`) and entered into MMS Radius before it can be
     * authorized on the controller -- see RoutersIndex::fetchZeroTierNodeId().
     *
     * @return list<string>
     */
    /**
     * Confirmed live 2026-09-21: joining the network alone left the router with
     * no IP address bound to its ZeroTier interface at all, even after the
     * controller authorized it with an ipAssignments value -- that field is
     * only auto-pushed to the client when the network's own v4AssignMode.zt is
     * enabled, which this app deliberately leaves off (see
     * ZeroTierControllerService's docblock: the app assigns IPs explicitly via
     * $router->zerotier_ip instead of ZeroTier's own pool). Nothing applies
     * that IP to the interface on its own, unlike WireGuard's own address line
     * a few lines up -- this had no effect on the ZeroTier tunnel/authorization
     * status itself (both showed fine), only on whether anything could
     * actually reach the router's RouterOS API over it. "zerotier1" mirrors
     * ZeroTierControllerService::ZEROTIER_INSTANCE_NAME's "zt1" assumption --
     * confirmed true on the real hardware this was tested against (RouterOS
     * auto-numbers its first ZeroTier interface object this way), not
     * guaranteed universally.
     *
     * A second issue surfaced immediately after fixing the first, also
     * confirmed live 2026-09-21: "/zerotier interface add" returns control to
     * the next script line before RouterOS has actually finished registering
     * the resulting interface, so the very next line addressing it failed
     * outright ("input does not match any value of interface") even though
     * "zerotier1" was confirmed to be the correct, eventual name. `:delay 3s`
     * gives RouterOS time to finish before the address line runs -- a
     * generous, deliberately simple fixed wait rather than a polling loop,
     * since this only costs a few seconds once during bootstrap.
     */
    private function zeroTierLines(Router $router): array
    {
        if (! $this->includesZeroTier($router)) {
            return [];
        }

        $networkId = config('services.zerotier.network_id');

        return array_filter([
            '',
            '# ZeroTier fallback tunnel. Requires the "zerotier" RouterOS package (RouterOS 7.5+,',
            '# ARM/ARM64 hardware ONLY -- upload the .npk and reboot BEFORE running this section;',
            '# see docs/zerotier-fallback-setup.md. Skip this whole block on MIPSBE/SMIPS boards.',
            '/zerotier enable zt1',
            '/zerotier interface add network='.$networkId.' instance=zt1',
            '# This router\'s ZeroTier node ID only exists after the line above runs. Retrieve it',
            '# with "/zerotier print" and enter it into MMS Radius so it can be authorized.',
            filled($router->zerotier_ip)
                ? ':delay 3s'
                : null,
            filled($router->zerotier_ip)
                ? '/ip address add address='.$router->zerotier_ip.'/24 interface=zerotier1 comment="MMS Radius ZeroTier IP"'
                : '# Once this router has a saved ZeroTier IP in MMS Radius, re-generate this script to add the "/ip address add ... interface=zerotier1" line -- without it, the tunnel and authorization can show fine while the RouterOS API is still unreachable over it.',
        ]);
    }

    /**
     * One `/radius add` line per enabled tunnel -- RouterOS's own RADIUS
     * client already fails over across multiple entries, tried in the order
     * they appear in `/radius print` (there is no `priority` property on
     * this menu at all -- confirmed live 2026-08-19: RouterOS rejected
     * `priority=1`/`priority=2` outright with "unknown parameter priority",
     * an assumption that had never actually been exercised against a real
     * router before that report). Correct failover order falls out for free
     * from emitting WireGuard's line before ZeroTier's line here -- as long
     * as an entry is never removed and blindly re-added, list order (and so
     * "try WireGuard before ZeroTier") is preserved with no explicit field
     * needed. See RouterOsConnectionService::syncRadiusClients() for the
     * live-API equivalent, which relies on the same "WireGuard already
     * exists, ZeroTier gets appended after it" ordering.
     *
     * @return list<string>
     */
    private function radiusClientLines(Router $router, string $service): array
    {
        $secret = $this->quote($router->shared_secret);
        $authPort = config('services.radius.auth_port');
        $acctPort = config('services.radius.acct_port');
        $lines = [];

        if ($this->includesWireguard($router)) {
            $lines[] = '/radius add address='.config('services.radius.server_ip').' secret="'.$secret.'" service='.$service.' authentication-port='.$authPort.' accounting-port='.$acctPort.' timeout=1000ms';
        }

        if ($this->includesZeroTier($router)) {
            $lines[] = '/radius add address='.config('services.zerotier.pi_ip').' secret="'.$secret.'" service='.$service.' authentication-port='.$authPort.' accounting-port='.$acctPort.' timeout=1000ms';
        }

        return $lines;
    }

    /**
     * The subnet(s) allowed to reach this router's RouterOS API -- must
     * include the ZeroTier /24 too once a router depends on that path, or
     * "Provision via API"/live monitoring/the ZeroTier-node-ID auto-fetch
     * button all silently fail: the tunnel itself works, but RouterOS's own
     * firewall still only trusts the WireGuard subnet. Public since
     * RouterOsConnectionService::syncApiServiceRestriction() reuses this
     * exact logic to keep an already-provisioned router's live firewall
     * trust-list in sync too, rather than only ever setting it once at
     * script time -- a router provisioned before ZeroTier was added to it
     * (or switched tunnel_mode afterward) never gets this updated
     * otherwise, since it's not part of any of the other live provisioning
     * steps.
     */
    public function apiServiceAddressRestriction(Router $router): string
    {
        $ranges = array_filter([
            $this->includesWireguard($router) ? '10.8.0.0/24' : null,
            $this->includesZeroTier($router) ? config('services.zerotier.ip_prefix').'.0/24' : null,
        ]);

        return implode(',', $ranges) ?: '10.8.0.0/24';
    }

    /**
     * The WireGuard endpoint a router's script should dial. Defaults to the
     * app-wide public endpoint (services.wireguard.endpoint_host/port), but
     * a router can override this -- the one case that needs it is a router
     * physically co-located with the Pi on the same LAN, where dialing the
     * public endpoint routes through NAT hairpin/loopback that many routers
     * don't support for UDP and the handshake silently never completes. The
     * override should be the Pi's LAN IP in that case.
     *
     * @return array{host: string, port: int, source: string}
     */
    private function wireguardEndpoint(Router $router): array
    {
        $hasOverride = filled($router->wireguard_endpoint_override_host);

        return [
            'host' => $hasOverride ? (string) $router->wireguard_endpoint_override_host : (string) config('services.wireguard.endpoint_host'),
            'port' => (int) ($router->wireguard_endpoint_override_port ?: config('services.wireguard.endpoint_port')),
            'source' => $hasOverride ? 'router local override' : 'default public endpoint',
        ];
    }

    /**
     * Provisions a RouterOS API user so MMS Radius can both pull live
     * bandwidth/Wi-Fi scan/topology data AND push live provisioning writes
     * (RADIUS client, hotspot profile, walled-garden, PPPoE profile/server --
     * see RouterOsConnectionService::provisionHotspot()/provisionPppoe()/
     * syncWalledGarden()) over the same WireGuard tunnel used for RADIUS --
     * no separate credential setup step. The API service is restricted to
     * the WireGuard subnet only. Verify the exact user-group policy flags
     * against your RouterOS version before relying on this in production;
     * policy syntax has shifted slightly across RouterOS 7 releases.
     *
     * @return list<string>
     */
    private function apiUserProvisioningLines(Router $router): array
    {
        if (blank($router->api_password)) {
            return [
                '# No RouterOS API credentials generated yet for this router. Live monitoring, Wi-Fi scan, topology, and "Provision via API" stay unavailable until you re-save it in MMS Radius.',
            ];
        }

        $username = $this->quote($router->api_username ?: 'mmsradius-api');
        $password = $this->quote($router->api_password);

        // Deny-list of every policy RouterOS 7 recognizes except read+write+api+test+sensitive --
        // confirmed against a real RouterOS 7.18.2 router's own `/user group print
        // detail where name=full` output. Earlier revisions of this list included
        // "dude" and "tikapp", which RouterOS 7 rejects outright ("input does not
        // match any value of policy"), so the whole group (and therefore the API
        // user) silently never got created. "rest-api" replaces them -- RouterOS 7
        // added a separate REST API surface distinct from the classic binary API
        // this app actually uses, and it should stay denied.
        //
        // "write" is REQUIRED, not optional: "Provision via API" issues genuine
        // write commands (/radius/add, /ip/hotspot/*/add, /ip/hotspot/walled-garden/add).
        // "test" and "sensitive" are also granted so the connection checker and
        // generated diagnostic/provisioning flows can inspect secrets and run
        // RouterOS test commands consistently across fresh installs.
        // A prior revision of this policy denied write ("read-only monitoring
        // account"), which meant every one of those commands was silently rejected
        // by RouterOS -- and, compounding it, RouterOsConnectionService::runSteps()
        // didn't check for a RouterOS `!trap` (error) response at all, so the
        // rejection was reported back to the admin as success. Both are fixed
        // together: this account now genuinely needs write, and runSteps() now
        // actually detects a trap. A router bootstrapped before this fix needs its
        // script re-run (or a fresh "Provision via API" push once the API user's
        // policy line has been re-applied) before writes will actually take effect.
        //
        // "ftp" is also REQUIRED, despite the misleading name -- RouterOS overloads
        // this policy flag to gate local file-system writes generally (a holdover
        // from when file transfer/backup only happened over literal FTP), not just
        // the FTP *service* (which this app never enables via /ip service). It was
        // originally denied here on a least-privilege assumption before this account
        // needed to touch files at all. RouterOsConnectionService::pushHotspotLoginPage()
        // writes the hotspot login page via `/tool fetch dst-path=...`, which RouterOS
        // rejects with "failure: cannot open file: permission denied" without it --
        // confirmed live 2026-08-09. A router bootstrapped before this fix needs the
        // same remediation as the write-policy fix above: re-run the script, or just
        // `/user group set [find name=mmsradius-api-group] policy=<the string below>`
        // on the router directly.
        $policy = 'read,write,api,test,sensitive,ftp,!local,!telnet,!ssh,!reboot,!policy,!winbox,!password,!web,!sniff,!romon,!rest-api';

        return [
            // Update-in-place if the group/user already exist (e.g. this script is
            // being re-run after "Regenerate credentials") instead of erroring on a
            // duplicate name and silently leaving the router with stale credentials.
            ':if ([:len [/user group find name=mmsradius-api-group]] = 0) do={ /user group add name=mmsradius-api-group policy='.$policy.' comment="MMS Radius API provisioning access" } else={ /user group set [find name=mmsradius-api-group] policy='.$policy.' comment="MMS Radius API provisioning access" }',
            ':if ([:len [/user find name="'.$username.'"]] = 0) do={ /user add name="'.$username.'" password="'.$password.'" group=mmsradius-api-group comment="MMS Radius API provisioning" } else={ /user set [find name="'.$username.'"] password="'.$password.'" group=mmsradius-api-group comment="MMS Radius API provisioning" }',
            '/ip service set api disabled=no port=8728 address='.$this->apiServiceAddressRestriction($router),
        ];
    }

    /**
     * The `html-directory` value every hotspot profile this service generates
     * (`saas-prof` in generateScript(), `mms-hotspot-profile` in
     * generateFreshInfrastructureScript()) uses. Confirmed live 2026-09-22 as
     * a real, previously-unnoticed source of "can't log in after pasting the
     * script" reports: both used to hardcode "flash/hotspot" regardless of
     * where a router's login.html actually lives -- RouterOsConnectionService
     * ::pushHotspotLoginPage() already reads $router->hotspot_login_directory
     * (set via the "Hotspot Login Page" Live-tab section, or blank for a
     * router that's never needed a custom one) to know WHERE to push the
     * file, but the PROFILE's own html-directory property never used that
     * same saved value, so the two could silently drift out of sync -- a
     * router serving login.html from "hotspot" but whose profile still says
     * "flash/hotspot" fails to log anyone in, since RouterOS's HTTP server
     * looks for the file in the directory the active profile names, not
     * wherever it actually was written.
     */
    private function hotspotLoginDirectory(Router $router): string
    {
        return filled($router->hotspot_login_directory) ? $router->hotspot_login_directory : 'flash/hotspot';
    }

    /**
     * Walled-garden entries so a customer's browser can reach the hotspot
     * portal AND the shop's active payment gateway's hosted checkout page
     * BEFORE the device is RADIUS-authenticated -- without these, the
     * customer picks a package and pays, but the hotspot blocks the
     * redirect to the gateway's checkout domain entirely. The gateway host
     * list itself is per-provider, best-effort metadata in
     * PaymentGatewayCatalog -- see the caveat there.
     *
     * @return list<string>
     */
    private function walledGardenLines(Router $router, string $portalHost): array
    {
        $lines = ['/ip hotspot walled-garden add dst-host='.$portalHost.' action=allow'];

        foreach (PaymentGatewayCatalog::walledGardenHosts($router->shop?->paymentGateway()) as $host) {
            $lines[] = '/ip hotspot walled-garden add dst-host='.$host.' action=allow';
        }

        $lines[] = '/ip hotspot walled-garden add dst-host=*.cloudflare.com action=allow';

        // The "Message support" link on hotspot.payment-failed points here. Confirmed live
        // 2026-09-22: an unauthenticated device clicking it got net::ERR_CONNECTION_CLOSED --
        // the same HTTPS-outside-the-walled-garden connection reset documented above for
        // payment gateway domains, just never noticed for this one since it's not gateway-
        // specific and so was never covered by PaymentGatewayCatalog::walledGardenHosts().
        $lines[] = '/ip hotspot walled-garden add dst-host=*.wa.me action=allow';
        $lines[] = '/ip hotspot walled-garden add dst-host=wa.me action=allow';

        return $lines;
    }
}
