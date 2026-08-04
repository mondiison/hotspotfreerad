<?php

namespace App\Services;

use App\Models\Router;

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
            'l009_builtin_wifi' => [
                'name' => 'L009 built-in Wi-Fi test router',
                'summary' => 'MikroTik L009 profile using wifi1 as the open hotspot SSID while ether2 remains available as an AP/switch trunk.',
                'capacity' => 'Lab/testing or small pilot before adding external APs',
            ],
            'pppoe_isp' => [
                'name' => 'PPPoE ISP access',
                'summary' => 'Subscriber VLAN and PPPoE server foundation for CPE-based customers.',
                'capacity' => 'Fixed subscribers with package bandwidth from RADIUS',
            ],
        ];
    }

    public function generateScript(Router $router): string
    {
        $router->loadMissing('shop');

        $nasIdentifier = $router->nas_identifier;
        $sharedSecret = $router->shared_secret;
        $radiusIp = config('services.radius.server_ip');
        $authPort = config('services.radius.auth_port');
        $acctPort = config('services.radius.acct_port');
        $wgEndpointHost = config('services.wireguard.endpoint_host');
        $wgEndpointPort = config('services.wireguard.endpoint_port');
        $wgPublicKey = config('services.wireguard.public_key');
        $portalUrl = rtrim(config('app.url'), '/') . '/hotspot/portal';
        $portalHost = parse_url($portalUrl, PHP_URL_HOST) ?: config('services.mikrotik.hotspot_dns_name');
        $hotspotDnsName = config('services.mikrotik.hotspot_dns_name');

        return <<<SCRIPT
/system identity set name="{$nasIdentifier}"
/interface wireguard add name=wg-saas listen-port=13231 mtu=1420
/interface wireguard peers add interface=wg-saas public-key="{$wgPublicKey}" endpoint-address={$wgEndpointHost} endpoint-port={$wgEndpointPort} allowed-address=10.8.0.1/32 persistent-keepalive=25s
/ip address add address={$router->wireguard_internal_ip}/24 interface=wg-saas
/radius add address={$radiusIp} secret="{$sharedSecret}" service=hotspot,ppp authentication-port={$authPort} accounting-port={$acctPort} timeout=1000ms
/ip hotspot profile add name=saas-prof use-radius=yes login-by=http-chap,cookie,mac-cookie html-directory=flash/hotspot dns-name={$hotspotDnsName}
/ip hotspot profile set saas-prof radius-accounting=yes
/ip hotspot walled-garden add dst-host={$portalHost} action=allow
SCRIPT;
    }

    public function generatePppoeScript(Router $router): string
    {
        $nasIdentifier = $router->nas_identifier;
        $sharedSecret = $router->shared_secret;
        $radiusIp = config('services.radius.server_ip');
        $authPort = config('services.radius.auth_port');
        $acctPort = config('services.radius.acct_port');
        $wgEndpointHost = config('services.wireguard.endpoint_host');
        $wgEndpointPort = config('services.wireguard.endpoint_port');
        $wgPublicKey = config('services.wireguard.public_key');
        $pppoeInterface = 'bridge1';

        return <<<SCRIPT
/system identity set name="{$nasIdentifier}"
/interface wireguard add name=wg-saas listen-port=13231 mtu=1420
/interface wireguard peers add interface=wg-saas public-key="{$wgPublicKey}" endpoint-address={$wgEndpointHost} endpoint-port={$wgEndpointPort} allowed-address=10.8.0.1/32 persistent-keepalive=25s
/ip address add address={$router->wireguard_internal_ip}/24 interface=wg-saas
/radius add address={$radiusIp} secret="{$sharedSecret}" service=ppp authentication-port={$authPort} accounting-port={$acctPort} timeout=1000ms
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
# PPPoE bandwidth is controlled by MMS Radius packages through Mikrotik-Rate-Limit.
# Keep this profile generic; do not hard-code rate-limit here unless you want a router-side override.
/ppp profile add name=mms-pppoe-profile only-one=yes change-tcp-mss=yes
/interface pppoe-server server add interface={$pppoeInterface} service-name=mms-radius default-profile=mms-pppoe-profile authentication=pap,chap,mschap1,mschap2 disabled=no
SCRIPT;
    }

    public function generateFreshInfrastructureScript(Router $router, string $profile = 'starlink_plaza'): string
    {
        $router->loadMissing('shop.tenant');

        $settings = $this->provisioningSettings($router, $profile);
        $profile = $settings['profile'];
        $routerIdentity = $this->quote($router->nas_identifier);
        $sharedSecret = $this->quote($router->shared_secret);
        $radiusIp = config('services.radius.server_ip');
        $authPort = config('services.radius.auth_port');
        $acctPort = config('services.radius.acct_port');
        $wgEndpointHost = config('services.wireguard.endpoint_host');
        $wgEndpointPort = config('services.wireguard.endpoint_port');
        $wgPublicKey = $this->quote(config('services.wireguard.public_key'));
        $portalUrl = rtrim(config('app.url'), '/') . '/hotspot/portal';
        $portalHost = parse_url($portalUrl, PHP_URL_HOST) ?: config('services.mikrotik.hotspot_dns_name');
        $hotspotDnsName = config('services.mikrotik.hotspot_dns_name');
        $enableBuiltinWifi = (bool) $settings['enable_builtin_wifi'];
        $builtinWifiInterface = $settings['builtin_wifi_interface'];

        $allVlans = array_filter([
            $settings['mgmt_vlan'],
            $settings['hotspot_vlan'],
            $settings['staff_vlan'],
            $settings['enable_pppoe'] ? $settings['pppoe_vlan'] : null,
            $settings['enable_pos'] ? $settings['pos_vlan'] : null,
        ]);

        $taggedVlans = implode(',', $allVlans);
        $nonHotspotTaggedVlans = implode(',', array_filter(
            $allVlans,
            fn ($vlan): bool => (string) $vlan !== (string) $settings['hotspot_vlan']
        ));
        $lanBridgeName = 'bridge-lan';
        $taggedPorts = $lanBridgeName.','.$settings['trunk_port'];

        $bridgeVlanLines = $enableBuiltinWifi
            ? array_filter([
                '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' untagged='.$builtinWifiInterface.' vlan-ids='.$settings['hotspot_vlan'],
                $nonHotspotTaggedVlans !== '' ? '/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' vlan-ids='.$nonHotspotTaggedVlans : null,
            ])
            : ['/interface bridge vlan add bridge='.$lanBridgeName.' tagged='.$taggedPorts.' vlan-ids='.$taggedVlans];

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
            '# Optional POS MAC-auth hotspot. Enable when POS devices are registered in MMS Radius by MAC address.',
            '# /ip hotspot profile add name=mms-pos-profile use-radius=yes login-by=mac radius-accounting=yes',
            '# /ip hotspot add name=mms-pos interface=vlan-pos address-pool=pool-pos profile=mms-pos-profile disabled=no',
        ] : [
            '',
            '# POS VLAN is disabled for this router profile. Enable it when POS terminals need password Wi-Fi and app-managed renewal.',
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
            '/interface wifi configuration add name=mms-open-hotspot-cfg mode=ap ssid="MMS Hotspot" security=mms-open-hotspot-sec country=Nigeria',
            '/interface wifi set [find default-name=$builtinWifiInterface] configuration=mms-open-hotspot-cfg disabled=no',
            '/interface bridge port add bridge=$lanBridge interface=$builtinWifiInterface pvid=$hotspotVlan comment="L009 built-in open hotspot Wi-Fi"',
        ] : [
            '',
            '# Built-in MikroTik Wi-Fi is disabled for this profile. Use the AP/switch trunk for external APs.',
        ];

        return implode("\n", array_merge([
            '# MMS Radius flexible MikroTik infrastructure script',
            '# Profile: '.$this->infrastructureProfiles()[$profile]['name'],
            '# Use on a fresh/no-default-config router, or review each section before pasting on an existing router.',
            '# Edit these global values first to match the tenant hardware and cabling.',
            '# Global variables are used so this works when pasted directly into RouterOS terminal.',
            ':global wan1 "'.$settings['wan1'].'"',
            ':global wan2 "'.$settings['wan2'].'"',
            ':global lanBridge "bridge-lan"',
            ':global trunkPort "'.$settings['trunk_port'].'"',
            ':global builtinWifiInterface "'.$builtinWifiInterface.'"',
            ':global mgmtVlan "'.$settings['mgmt_vlan'].'"',
            ':global hotspotVlan "'.$settings['hotspot_vlan'].'"',
            ':global staffVlan "'.$settings['staff_vlan'].'"',
            ':global pppoeVlan "'.$settings['pppoe_vlan'].'"',
            ':global posVlan "'.$settings['pos_vlan'].'"',
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
            '/interface bridge port add bridge=$lanBridge interface=$trunkPort comment="AP/switch trunk carrying MMS Radius VLANs"',
            '/interface vlan add interface=$lanBridge name=vlan-mgmt vlan-id=$mgmtVlan',
            '/interface vlan add interface=$lanBridge name=vlan-hotspot vlan-id=$hotspotVlan',
            '/interface vlan add interface=$lanBridge name=vlan-staff vlan-id=$staffVlan',
            $settings['enable_pppoe'] ? '/interface vlan add interface=$lanBridge name=vlan-pppoe vlan-id=$pppoeVlan' : '# PPPoE VLAN interface disabled',
            $settings['enable_pos'] ? '/interface vlan add interface=$lanBridge name=vlan-pos vlan-id=$posVlan' : '# POS VLAN interface disabled',
        ], $builtinWifiLines, $bridgeVlanLines, [
            '/interface bridge set $lanBridge vlan-filtering=yes',
            '',
            '/interface wireguard add name=wg-saas listen-port=13231 mtu=1420',
            '/interface wireguard peers add interface=wg-saas public-key="'.$wgPublicKey.'" endpoint-address='.$wgEndpointHost.' endpoint-port='.$wgEndpointPort.' allowed-address=10.8.0.1/32 persistent-keepalive=25s',
            '/ip address add address='.$router->wireguard_internal_ip.'/24 interface=wg-saas comment="MMS Radius WireGuard IP"',
            '/radius add address='.$radiusIp.' secret="'.$sharedSecret.'" service=hotspot,ppp authentication-port='.$authPort.' accounting-port='.$acctPort.' timeout=1000ms',
            '',
            '/ip address add address=$hotspotGateway interface=vlan-hotspot comment="Open customer hotspot VLAN"',
            '/ip pool add name=pool-hotspot ranges=$hotspotPool',
            '/ip dhcp-server add name=dhcp-hotspot interface=vlan-hotspot address-pool=pool-hotspot lease-time=30m disabled=no',
            '/ip dhcp-server network add address=$hotspotNetwork gateway='.str($settings['hotspot_gateway'])->before('/').' dns-server='.str($settings['hotspot_gateway'])->before('/'),
            '# DHCP for MMS Hotspot is served from vlan-hotspot. Do not attach hotspot DHCP directly to wifi1/ether ports because bridge member ports become slave interfaces.',
            '/ip hotspot profile add name=mms-hotspot-profile use-radius=yes login-by=http-chap,cookie,mac-cookie html-directory=flash/hotspot dns-name='.$hotspotDnsName.' radius-accounting=yes',
            '/ip hotspot add name=mms-hotspot interface=vlan-hotspot address-pool=pool-hotspot profile=mms-hotspot-profile disabled=no',
            '/ip hotspot walled-garden add dst-host='.$portalHost.' action=allow',
            '/ip hotspot walled-garden add dst-host=*.flutterwave.com action=allow',
            '/ip hotspot walled-garden add dst-host=*.cloudflare.com action=allow',
            '',
            '/ip address add address=$staffGateway interface=vlan-staff comment="Password staff/admin SSID VLAN"',
            '/ip pool add name=pool-staff ranges=$staffPool',
            '/ip dhcp-server add name=dhcp-staff interface=vlan-staff address-pool=pool-staff lease-time=8h disabled=no',
            '/ip dhcp-server network add address=$staffNetwork gateway='.str($settings['staff_gateway'])->before('/').' dns-server='.str($settings['staff_gateway'])->before('/'),
        ], $posLines, $pppoeLines, [
            '',
            '/ip firewall address-list add list=mms-hotspot-subnets address=$hotspotNetwork',
            $settings['enable_pos'] ? '/ip firewall address-list add list=mms-pos-subnets address=$posNetwork' : '# POS firewall list disabled',
            '/ip firewall filter add chain=input connection-state=established,related action=accept',
            '/ip firewall filter add chain=input connection-state=invalid action=drop',
            '/ip firewall filter add chain=input in-interface=wg-saas action=accept comment="Allow MMS Radius tunnel"',
            '/ip firewall filter add chain=input in-interface=vlan-mgmt action=accept comment="Allow management VLAN to router"',
            '/ip firewall filter add chain=input protocol=udp dst-port=53,67 action=accept comment="Allow DNS/DHCP from client VLANs"',
            '/ip firewall filter add chain=input in-interface=vlan-hotspot protocol=tcp dst-port=80,443,64872-64875 action=accept comment="Allow hotspot captive portal services"',
            '/ip firewall filter add chain=input in-interface=vlan-pos protocol=tcp dst-port=80,443,64872-64875 action=accept comment="Allow optional POS MAC-auth hotspot services"',
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
            '# MMS Hotspot = open SSID tagged VLAN '.$settings['hotspot_vlan'].', captive portal',
            '# MMS Staff = WPA2/WPA3 SSID tagged VLAN '.$settings['staff_vlan'],
            '# MMS POS = WPA2/WPA3 SSID tagged VLAN '.$settings['pos_vlan'].', hidden optional, registered devices',
            '# MMS Mgmt = wired or restricted SSID tagged VLAN '.$settings['mgmt_vlan'],
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
        $portalUrl = rtrim(config('app.url'), '/') . '/hotspot/portal';

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
            'l009_builtin_wifi' => [
                'wan1' => 'ether1',
                'wan2' => 'ether7',
                'trunk_port' => 'ether2',
                'download_limit' => '80M',
                'upload_limit' => '15M',
            ],
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

    private function provisioningSettings(Router $router, string $profile): array
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
            'download_limit' => $this->profileDefaults($profile)['download_limit'],
            'upload_limit' => $this->profileDefaults($profile)['upload_limit'],
            'mgmt_vlan' => 10,
            'hotspot_vlan' => 20,
            'staff_vlan' => 30,
            'pppoe_vlan' => 40,
            'pos_vlan' => 50,
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
            'enable_builtin_wifi' => $profile === 'l009_builtin_wifi',
            'enable_pos' => $profile !== 'l009_builtin_wifi',
            'enable_pppoe' => ! in_array($profile, ['small_hotspot', 'l009_builtin_wifi'], true),
            'enable_realtime_qos' => true,
            'enable_second_wan' => false,
        ];

        return array_replace($defaults, $settings);
    }

    private function quote(?string $value): string
    {
        return str_replace('"', '\"', (string) $value);
    }
}
