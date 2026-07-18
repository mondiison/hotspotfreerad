<?php

namespace App\Services;

use App\Models\Router;

class MikroTikProvisioningService
{
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
/radius add address={$radiusIp} secret="{$sharedSecret}" service=hotspot authentication-port={$authPort} accounting-port={$acctPort} timeout=1000ms
/ip hotspot profile add name=saas-prof use-radius=yes login-by=http-chap,cookie,mac-cookie html-directory=flash/hotspot dns-name={$hotspotDnsName}
/ip hotspot profile set saas-prof radius-accounting=yes
/ip hotspot walled-garden add dst-host={$portalHost} action=allow
SCRIPT;
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
}
