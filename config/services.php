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
    ],

    'wireguard' => [
        'endpoint_host' => env('WIREGUARD_ENDPOINT_HOST', 'YOUR_PI_PUBLIC_IP'),
        'endpoint_port' => (int) env('WIREGUARD_ENDPOINT_PORT', 13231),
        'public_key' => env('WIREGUARD_PUBLIC_KEY', 'YOUR_PI_WG_PUBLIC_KEY'),
    ],

    'mikrotik' => [
        'hotspot_dns_name' => env('HOTSPOT_DNS_NAME', 'hotspot.local'),
    ],

    'flutterwave' => [
        'auth_url' => env('FLUTTERWAVE_AUTH_URL', 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://developersandbox-api.flutterwave.com'),
        'client_id' => env('FLUTTERWAVE_CLIENT_ID'),
        'client_secret' => env('FLUTTERWAVE_CLIENT_SECRET'),
        'default_payment_method' => env('FLUTTERWAVE_DEFAULT_PAYMENT_METHOD', 'opay'),
        'webhook_secret_hash' => env('FLUTTERWAVE_WEBHOOK_SECRET_HASH'),
    ],

];
