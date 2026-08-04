<?php

namespace App\Support;

use App\Models\Shop;
use App\Services\PlatformPaymentSettingsService;

class PaymentGatewayCatalog
{
    public const FLUTTERWAVE = 'flutterwave';

    public static function onlineGateways(): array
    {
        return [
            self::FLUTTERWAVE => [
                'key' => self::FLUTTERWAVE,
                'name' => 'Flutterwave',
                'status' => 'live',
                'summary' => 'Live adapter for OPay, bank transfer, and card checkout.',
            ],
            'paystack' => [
                'key' => 'paystack',
                'name' => 'Paystack',
                'status' => 'planned',
                'summary' => 'Vendor-ready placeholder for future Paystack checkout.',
            ],
            'monnify' => [
                'key' => 'monnify',
                'name' => 'Monnify',
                'status' => 'planned',
                'summary' => 'Vendor-ready placeholder for reserved accounts and transfer collection.',
            ],
            'squad' => [
                'key' => 'squad',
                'name' => 'Squad',
                'status' => 'planned',
                'summary' => 'Vendor-ready placeholder for GTCO Squad collections.',
            ],
            'manual_bank' => [
                'key' => 'manual_bank',
                'name' => 'Manual bank transfer',
                'status' => 'planned',
                'summary' => 'Vendor-ready placeholder for admin-confirmed offline transfer.',
            ],
        ];
    }

    public static function gatewayOptions(): array
    {
        return collect(self::onlineGateways())
            ->mapWithKeys(fn (array $gateway, string $key): array => [
                $key => $gateway['name'].($gateway['status'] === 'live' ? ' (live)' : ' (coming soon)'),
            ])
            ->all();
    }

    public static function gatewayName(?string $gateway): string
    {
        return self::onlineGateways()[$gateway ?: self::FLUTTERWAVE]['name'] ?? str((string) $gateway)->replace('_', ' ')->headline()->toString();
    }

    public static function implementedGatewayKeys(): array
    {
        return [self::FLUTTERWAVE];
    }

    public static function tenantProvider(?string $gatewayKey = null): array
    {
        $gatewayKey = $gatewayKey ?: self::FLUTTERWAVE;
        $gateway = self::onlineGateways()[$gatewayKey] ?? self::onlineGateways()[self::FLUTTERWAVE];

        return [
            'key' => $gateway['key'],
            'name' => $gateway['name'],
            'label' => 'Default tenant gateway',
            'summary' => 'Customer hotspot payments settle into the payment account configured for each tenant shop.',
            'status' => $gateway['status'],
            'channels' => [
                'opay_transfer' => [
                    'label' => 'OPay and transfer',
                    'requires' => 'v4 Client ID + Client Secret',
                    'description' => 'Used by the live gateway adapter for direct charge, OPay, and bank transfer collection from the captive portal.',
                ],
                'card' => [
                    'label' => 'Card checkout',
                    'requires' => 'v3 Secret Key',
                    'description' => 'Used when the customer chooses card checkout from the hosted payment page.',
                ],
                'webhook' => [
                    'label' => 'Webhook confirmation',
                    'requires' => 'Webhook secret hash',
                    'description' => 'Allows the selected gateway to confirm payments even if the customer closes the browser.',
                ],
            ],
        ];
    }

    public static function platformProvider(?string $gatewayKey = null): array
    {
        $settings = app(PlatformPaymentSettingsService::class);
        $gatewayKey = $gatewayKey ?: $settings->activeGateway();
        $gateway = self::onlineGateways()[$gatewayKey] ?? self::onlineGateways()[self::FLUTTERWAVE];

        return [
            'key' => $gateway['key'],
            'name' => $gateway['name'],
            'label' => 'Default platform gateway',
            'summary' => 'Tenant subscription payments settle into the MMS Radius platform billing account.',
            'status' => $gateway['status'],
            'channels' => [
                'checkout' => [
                    'label' => 'Platform checkout',
                    'requires' => 'Platform gateway credentials',
                    'description' => 'Used when tenants pay or renew their SaaS subscription.',
                    'ready' => $settings->activeGatewayIsImplemented() && filled($settings->clientId()) && filled($settings->clientSecret()),
                ],
                'webhook' => [
                    'label' => 'Platform webhook',
                    'requires' => 'Platform webhook secret',
                    'description' => 'Keeps platform billing active even when callback redirects are interrupted.',
                    'ready' => $settings->activeGatewayIsImplemented() && filled($settings->webhookSecretHash()),
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
            'flutterwave' => 'Default gateway online',
            'voucher_cash' => 'Voucher cash',
        ];
    }

    public static function providerLabel(?string $provider): string
    {
        return self::paymentMethods()[$provider] ?? str((string) $provider)->replace('_', ' ')->headline()->toString();
    }
}
