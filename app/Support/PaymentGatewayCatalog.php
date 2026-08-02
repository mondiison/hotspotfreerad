<?php

namespace App\Support;

use App\Models\Shop;
use App\Services\PlatformPaymentSettingsService;

class PaymentGatewayCatalog
{
    public static function tenantProvider(): array
    {
        return [
            'key' => 'flutterwave',
            'name' => 'Flutterwave',
            'label' => 'Tenant Flutterwave',
            'summary' => 'Customer hotspot payments settle into this shop or tenant Flutterwave account.',
            'channels' => [
                'opay_transfer' => [
                    'label' => 'OPay and transfer',
                    'requires' => 'v4 Client ID + Client Secret',
                    'description' => 'Used for direct charge, OPay, and bank transfer collection from the captive portal.',
                ],
                'card' => [
                    'label' => 'Card checkout',
                    'requires' => 'v3 Secret Key',
                    'description' => 'Used when the customer chooses card checkout from Flutterwave hosted payment.',
                ],
                'webhook' => [
                    'label' => 'Webhook confirmation',
                    'requires' => 'Webhook secret hash',
                    'description' => 'Allows Flutterwave to confirm payments even if the customer closes the browser.',
                ],
            ],
        ];
    }

    public static function platformProvider(): array
    {
        $settings = app(PlatformPaymentSettingsService::class);

        return [
            'key' => 'flutterwave',
            'name' => 'Flutterwave',
            'label' => 'Platform Flutterwave',
            'summary' => 'Tenant subscription payments settle into the MMS Radius platform account.',
            'channels' => [
                'checkout' => [
                    'label' => 'Platform checkout',
                    'requires' => 'Platform Client ID + Client Secret',
                    'description' => 'Used when tenants pay or renew their SaaS subscription.',
                    'ready' => filled($settings->clientId()) && filled($settings->clientSecret()),
                ],
                'webhook' => [
                    'label' => 'Platform webhook',
                    'requires' => 'Platform webhook secret hash',
                    'description' => 'Keeps platform billing active even when callback redirects are interrupted.',
                    'ready' => filled($settings->webhookSecretHash()),
                ],
            ],
        ];
    }

    public static function tenantReadiness(Shop $shop): array
    {
        return [
            'opay_transfer' => [
                'label' => 'OPay/transfer',
                'ready_badge' => 'OPay/transfer ready',
                'missing_badge' => 'OPay/transfer missing',
                'ready' => $shop->hasCompleteFlutterwaveCredentials(),
                'hint' => $shop->hasCompleteFlutterwaveCredentials()
                    ? 'v4 client credentials saved'
                    : 'Needs v4 Client ID and Client Secret',
            ],
            'card' => [
                'label' => 'Card checkout',
                'ready_badge' => 'Card checkout ready',
                'missing_badge' => 'Card key missing',
                'ready' => $shop->hasFlutterwaveHostedCheckoutKey(),
                'hint' => $shop->hasFlutterwaveHostedCheckoutKey()
                    ? 'v3 secret key saved'
                    : 'Needs v3 Secret Key',
            ],
            'webhook' => [
                'label' => 'Webhook',
                'ready_badge' => 'Webhook ready',
                'missing_badge' => 'Webhook missing',
                'ready' => $shop->hasFlutterwaveWebhookSecret(),
                'hint' => $shop->hasFlutterwaveWebhookSecret()
                    ? 'secret hash saved'
                    : 'Recommended for automatic confirmation',
            ],
        ];
    }

    public static function paymentMethods(): array
    {
        return [
            'flutterwave' => 'Flutterwave online',
            'voucher_cash' => 'Voucher cash',
        ];
    }

    public static function providerLabel(?string $provider): string
    {
        return self::paymentMethods()[$provider] ?? str((string) $provider)->replace('_', ' ')->headline()->toString();
    }
}
