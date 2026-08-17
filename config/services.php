<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'radius' => [
        'server_ip' => env('RADIUS_SERVER_IP', '10.8.0.1'),
        'auth_port' => (int) env('RADIUS_AUTH_PORT', 1812),
        'acct_port' => (int) env('RADIUS_ACCT_PORT', 1813),
        'manage_clients' => (bool) env('RADIUS_MANAGE_CLIENTS', false),
        'clients_file' => env('RADIUS_CLIENTS_FILE', '/etc/freeradius/3.0/clients.d/hotspotfreerad.conf'),
        'reload_command' => explode(' ', env('RADIUS_RELOAD_COMMAND', 'sudo -n systemctl reload-or-restart freeradius')),
    ],

    'wireguard' => [
        'endpoint_host' => env('WIREGUARD_ENDPOINT_HOST', 'YOUR_PI_PUBLIC_IP'),
        'endpoint_port' => (int) env('WIREGUARD_ENDPOINT_PORT', 13231),
        'local_endpoint_host' => env('WIREGUARD_LOCAL_ENDPOINT_HOST'),
        'public_key' => env('WIREGUARD_PUBLIC_KEY', 'YOUR_PI_WG_PUBLIC_KEY'),
        'interface' => env('WIREGUARD_INTERFACE', 'wg-saas'),
        'manage_peers' => (bool) env('WIREGUARD_MANAGE_PEERS', false),
        // Takes no CLI arguments (reads "public-key\nallowed-ip\n" from stdin) so the
        // sudoers NOPASSWD rule for it needs no wildcards -- see docs/wireguard-server-setup.md.
        'set_peer_script' => env('WIREGUARD_SET_PEER_SCRIPT', '/usr/local/sbin/hotspotfreerad-wg-set-peer'),
        // Separate on/off switch from manage_peers -- this one touches the Pi's kernel
        // routing table (adding/removing "ip route ... proto hotspotfreerad" entries for
        // routers with provisioning_settings.route_lan_through_tunnel enabled), a
        // meaningfully different risk class from just updating WireGuard peer state, so
        // it gets its own explicit opt-in even though the proto tag makes removal safe.
        'manage_routes' => (bool) env('WIREGUARD_MANAGE_ROUTES', false),
        // Same stdin-driven, no-CLI-args shape as set_peer_script, for the same sudoers reason.
        'set_route_script' => env('WIREGUARD_SET_ROUTE_SCRIPT', '/usr/local/sbin/hotspotfreerad-wg-set-route'),
        // Custom protocol name every route this app manages is tagged with (registered
        // once in /etc/iproute2/rt_protos -- see docs/wireguard-server-setup.md), so
        // reconciliation can safely add/remove routes without ever touching anything
        // else in the Pi's routing table.
        'route_proto' => env('WIREGUARD_ROUTE_PROTO', 'hotspotfreerad'),
    ],

    'mikrotik' => [
        'hotspot_dns_name' => env('HOTSPOT_DNS_NAME', 'hotspot.local'),
        'portal_url' => env('HOTSPOT_PORTAL_URL'),
    ],

    'zerotier' => [
        // Self-hosted controller's local API -- reachable only from the Pi itself (loopback),
        // since the app and the controller run on the same machine. Not ZeroTier Central --
        // see docs/zerotier-fallback-setup.md for why (free-tier Central has no API access at all).
        'controller_url' => env('ZEROTIER_CONTROLLER_URL', 'http://127.0.0.1:9993'),
        // Path to the local zerotier-one service's own auth token file -- read at call time,
        // never stored as a secret in .env (the file is managed/rotated by zerotier-one itself).
        'auth_token_path' => env('ZEROTIER_AUTH_TOKEN_PATH', '/var/lib/zerotier-one/authtoken.secret'),
        // 16-hex-char self-hosted network ID (Pi's own ZT node ID + a chosen 6-char suffix),
        // created once during Pi bootstrap -- see docs/zerotier-fallback-setup.md.
        'network_id' => env('ZEROTIER_NETWORK_ID', 'YOUR_ZEROTIER_NETWORK_ID'),
        // The Pi's own fixed address on the ZeroTier network -- routers dial this (not
        // 10.8.0.1, which is the WireGuard-side address) for RADIUS traffic over the
        // ZeroTier fallback path. Recorded by hand once during Pi bootstrap, since
        // ZeroTier (not this app) assigns it -- see docs/zerotier-fallback-setup.md.
        'pi_ip' => env('ZEROTIER_PI_IP', 'YOUR_PI_ZEROTIER_IP'),
        // The /24 this app suggests for router addresses and pushes as each authorized
        // router's fixed IP assignment. Kept out of WireGuard's 10.8.0.x range so the two
        // are never confusable.
        'ip_prefix' => env('ZEROTIER_IP_PREFIX', '10.9.0'),
        // Guarded the same way WIREGUARD_MANAGE_PEERS is -- false everywhere by default.
        'manage_members' => (bool) env('ZEROTIER_MANAGE_MEMBERS', false),
    ],

    'flutterwave' => [
        'auth_url' => env('FLUTTERWAVE_AUTH_URL', 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://developersandbox-api.flutterwave.com'),
        'standard_base_url' => env('FLUTTERWAVE_STANDARD_BASE_URL', 'https://api.flutterwave.com/v3'),
        'client_id' => env('FLUTTERWAVE_CLIENT_ID'),
        'client_secret' => env('FLUTTERWAVE_CLIENT_SECRET'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'default_payment_method' => env('FLUTTERWAVE_DEFAULT_PAYMENT_METHOD', 'opay'),
        'webhook_secret_hash' => env('FLUTTERWAVE_WEBHOOK_SECRET_HASH'),
    ],

    'paystack' => [
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'monnify' => [
        // Test/sandbox default -- per-shop "Environment" setting in Payment Setup
        // picks between this and live_base_url; env vars here only change the
        // defaults for shops that haven't explicitly saved an environment yet.
        'base_url' => env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'),
        'live_base_url' => env('MONNIFY_LIVE_BASE_URL', 'https://api.monnify.com'),
    ],

    'squad' => [
        'base_url' => env('SQUAD_BASE_URL', 'https://sandbox-api-d.squadco.com'),
        'live_base_url' => env('SQUAD_LIVE_BASE_URL', 'https://api-d.squadco.com'),
    ],

    'stripe' => [
        'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com/v1'),
    ],

];
