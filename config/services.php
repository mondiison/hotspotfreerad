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
        'public_key' => env('WIREGUARD_PUBLIC_KEY', 'YOUR_PI_WG_PUBLIC_KEY'),
        'interface' => env('WIREGUARD_INTERFACE', 'wg-saas'),
        'manage_peers' => (bool) env('WIREGUARD_MANAGE_PEERS', false),
        // Takes no CLI arguments (reads "public-key\nallowed-ip\n" from stdin) so the
        // sudoers NOPASSWD rule for it needs no wildcards -- see docs/wireguard-server-setup.md.
        'set_peer_script' => env('WIREGUARD_SET_PEER_SCRIPT', '/usr/local/sbin/hotspotfreerad-wg-set-peer'),
    ],

    'mikrotik' => [
        'hotspot_dns_name' => env('HOTSPOT_DNS_NAME', 'hotspot.local'),
        'portal_url' => env('HOTSPOT_PORTAL_URL'),
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
