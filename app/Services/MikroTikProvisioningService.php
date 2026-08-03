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
/ppp profile add name=mms-pppoe-profile use-radius=yes only-one=yes change-tcp-mss=yes
/interface pppoe-server server add interface={$pppoeInterface} service-name=mms-radius default-profile=mms-pppoe-profile authentication=pap,chap,mschap1,mschap2 disabled=no
SCRIPT;
    }

    public function generateFreshInfrastructureScript(Router $router, string $profile = 'starlink_plaza'): string
    {
        $router->loadMissing('shop.tenant');

        $profile = array_key_exists($profile, $this->infrastructureProfiles()) ? $profile : 'starlink_plaza';
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

        $defaults = $this->profileDefaults($profile);

        return implode("\n", [
            '# MMS Radius flexible MikroTik infrastructure script',
            '# Profile: '.$this->infrastructureProfiles()[$profile]['name'],
            '# Use on a fresh/no-default-config router, or review each section before pasting on an existing router.',
            '# Edit these local values first to match the tenant hardware and cabling.',
            ':local wan1 "'.$defaults['wan1'].'"',
            ':local wan2 "'.$defaults['wan2'].'"',
            ':local lanBridge "bridge-lan"',
            ':local trunkPort "'.$defaults['trunk_port'].'"',
            ':local mgmtVlan "10"',
            ':local hotspotVlan "20"',
            ':local staffVlan "30"',
            ':local pppoeVlan "40"',
            ':local posVlan "50"',
            ':local hotspotGateway "10.5.50.1/23"',
            ':local hotspotPool "10.5.50.10-10.5.51.250"',
            ':local staffGateway "192.168.30.1/24"',
            ':local staffPool "192.168.30.10-192.168.30.250"',
            ':local posGateway "192.168.50.1/24"',
            ':local posPool "192.168.50.10-192.168.50.250"',
            ':local pppoeGateway "172.16.40.1/24"',
            ':local downloadLimit "'.$defaults['download_limit'].'"',
            ':local uploadLimit "'.$defaults['upload_limit'].'"',
            '',
            '/system identity set name="'.$routerIdentity.'"',
            '/ip dns set allow-remote-requests=yes servers=1.1.1.1,8.8.8.8',
            '/interface list add name=WAN comment="Internet uplinks such as Starlink"',
            '/interface list member add list=WAN interface=$wan1',
            '# Add this when second Starlink is connected: /interface list member add list=WAN interface=$wan2',
            '/interface bridge add name=$lanBridge protocol-mode=rstp vlan-filtering=no comment="MMS Radius LAN bridge"',
            '/interface bridge port add bridge=$lanBridge interface=$trunkPort comment="AP/switch trunk carrying VLAN 10,20,30,40,50"',
            '/interface vlan add interface=$lanBridge name=vlan-mgmt vlan-id=$mgmtVlan',
            '/interface vlan add interface=$lanBridge name=vlan-hotspot vlan-id=$hotspotVlan',
            '/interface vlan add interface=$lanBridge name=vlan-staff vlan-id=$staffVlan',
            '/interface vlan add interface=$lanBridge name=vlan-pppoe vlan-id=$pppoeVlan',
            '/interface vlan add interface=$lanBridge name=vlan-pos vlan-id=$posVlan',
            '/interface bridge vlan add bridge=$lanBridge tagged=$lanBridge,$trunkPort vlan-ids=10,20,30,40,50',
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
            '/ip dhcp-server network add address=10.5.50.0/23 gateway=10.5.50.1 dns-server=10.5.50.1',
            '/ip hotspot profile add name=mms-hotspot-profile use-radius=yes login-by=http-chap,cookie,mac-cookie html-directory=flash/hotspot dns-name='.$hotspotDnsName.' radius-accounting=yes',
            '/ip hotspot add name=mms-hotspot interface=vlan-hotspot address-pool=pool-hotspot profile=mms-hotspot-profile disabled=no',
            '/ip hotspot walled-garden add dst-host='.$portalHost.' action=allow',
            '/ip hotspot walled-garden add dst-host=*.flutterwave.com action=allow',
            '/ip hotspot walled-garden add dst-host=*.cloudflare.com action=allow',
            '',
            '/ip address add address=$staffGateway interface=vlan-staff comment="Password staff/admin SSID VLAN"',
            '/ip pool add name=pool-staff ranges=$staffPool',
            '/ip dhcp-server add name=dhcp-staff interface=vlan-staff address-pool=pool-staff lease-time=8h disabled=no',
            '/ip dhcp-server network add address=192.168.30.0/24 gateway=192.168.30.1 dns-server=192.168.30.1',
            '',
            '/ip address add address=$posGateway interface=vlan-pos comment="POS SSID VLAN, registered devices, no shared customer password"',
            '/ip pool add name=pool-pos ranges=$posPool',
            '/ip dhcp-server add name=dhcp-pos interface=vlan-pos address-pool=pool-pos lease-time=12h disabled=no',
            '/ip dhcp-server network add address=192.168.50.0/24 gateway=192.168.50.1 dns-server=192.168.50.1',
            '# Optional POS MAC-auth hotspot. Enable when POS devices are registered in MMS Radius by MAC address.',
            '# /ip hotspot profile add name=mms-pos-profile use-radius=yes login-by=mac radius-accounting=yes',
            '# /ip hotspot add name=mms-pos interface=vlan-pos address-pool=pool-pos profile=mms-pos-profile disabled=no',
            '',
            '/ip address add address=$pppoeGateway interface=vlan-pppoe comment="Optional PPPoE/CPE VLAN"',
            '/ppp aaa set use-radius=yes accounting=yes interim-update=5m',
            '/ppp profile add name=mms-pppoe-profile use-radius=yes only-one=yes change-tcp-mss=yes',
            '/interface pppoe-server server add interface=vlan-pppoe service-name=mms-radius default-profile=mms-pppoe-profile authentication=pap,chap,mschap1,mschap2 disabled=no',
            '',
            '/ip firewall address-list add list=mms-hotspot-subnets address=10.5.50.0/23',
            '/ip firewall address-list add list=mms-pos-subnets address=192.168.50.0/24',
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
            '/ip firewall filter add chain=forward src-address=192.168.50.0/24 dst-address=10.0.0.0/8 action=drop comment="POS cannot reach private client/management networks"',
            '/ip firewall filter add chain=forward src-address=10.5.50.0/23 dst-address=192.168.0.0/16 action=drop comment="Hotspot clients cannot reach LAN/private networks"',
            '/ip firewall nat add chain=srcnat out-interface=$wan1 action=masquerade',
            '# Add this when second Starlink is connected: /ip firewall nat add chain=srcnat out-interface=$wan2 action=masquerade',
            '',
            '/queue type add name=pcq-hotspot-down kind=pcq pcq-classifier=dst-address pcq-rate=0 pcq-limit=50KiB pcq-total-limit=4000KiB',
            '/queue type add name=pcq-hotspot-up kind=pcq pcq-classifier=src-address pcq-rate=0 pcq-limit=50KiB pcq-total-limit=4000KiB',
            '/ip firewall mangle add chain=prerouting src-address=10.5.50.0/23 protocol=udp packet-size=0-600 action=mark-packet new-packet-mark=realtime-up passthrough=yes comment="Realtime voice/video small UDP upload"',
            '/ip firewall mangle add chain=postrouting dst-address=10.5.50.0/23 protocol=udp packet-size=0-600 action=mark-packet new-packet-mark=realtime-down passthrough=yes comment="Realtime voice/video small UDP download"',
            '/ip firewall mangle add chain=prerouting src-address=10.5.50.0/23 packet-mark=no-mark action=mark-packet new-packet-mark=hotspot-up passthrough=yes',
            '/ip firewall mangle add chain=postrouting dst-address=10.5.50.0/23 packet-mark=no-mark action=mark-packet new-packet-mark=hotspot-down passthrough=yes',
            '/queue tree add name=mms-upload parent=$wan1 max-limit=$uploadLimit',
            '/queue tree add name=mms-download parent=vlan-hotspot max-limit=$downloadLimit',
            '/queue tree add name=realtime-upload parent=mms-upload packet-mark=realtime-up priority=1 limit-at=2M max-limit=$uploadLimit',
            '/queue tree add name=realtime-download parent=mms-download packet-mark=realtime-down priority=1 limit-at=5M max-limit=$downloadLimit',
            '/queue tree add name=hotspot-upload parent=mms-upload packet-mark=hotspot-up queue=pcq-hotspot-up priority=5 max-limit=$uploadLimit',
            '/queue tree add name=hotspot-download parent=mms-download packet-mark=hotspot-down queue=pcq-hotspot-down priority=5 max-limit=$downloadLimit',
            '# Disable FastTrack for hotspot traffic if a default firewall later adds it; FastTrack can bypass queues.',
            '',
            '/system script add name=mms-starlink-bandwidth-policy source=":local downNormal \"'.$defaults['download_limit'].'\"; :local upNormal \"'.$defaults['upload_limit'].'\"; /queue tree set [find name=mms-download] max-limit=\\$downNormal; /queue tree set [find name=mms-upload] max-limit=\\$upNormal;"',
            '/system scheduler add name=mms-refresh-bandwidth interval=10m on-event=mms-starlink-bandwidth-policy comment="Adjust parent PCQ limits here as Starlink capacity changes"',
            '',
            '# AP SSID mapping recommended by MMS Radius:',
            '# MMS Hotspot = open SSID tagged VLAN 20, captive portal',
            '# MMS Staff = WPA2/WPA3 SSID tagged VLAN 30',
            '# MMS POS = WPA2/WPA3 SSID tagged VLAN 50, hidden optional, registered devices',
            '# MMS Mgmt = wired or restricted SSID tagged VLAN 10',
        ]);
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

    private function quote(?string $value): string
    {
        return str_replace('"', '\"', (string) $value);
    }
}
