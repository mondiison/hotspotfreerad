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
                'brand_color' => '#f5a623',
                'logo_path' => 'images/payment-gateways/flutterwave.svg',
                'region' => 'Africa and global',
                'currencies' => ['NGN', 'GHS', 'KES', 'UGX', 'TZS', 'ZAR', 'USD', 'GBP', 'EUR'],
                'status' => 'live',
                'summary' => 'Live adapter for OPay, bank transfer, and card checkout. Use v4 Client ID/Secret for OPay/transfer and v3 Secret Key for card.',
                'webhook_header' => 'verif-hash',
                'webhook_note' => 'Use the same Secret Hash saved here when configuring Flutterwave webhooks.',
                'fields' => [
                    'client_id' => 'Client ID (v4 OPay/transfer)',
                    'client_secret' => 'Client Secret (v4 OPay/transfer)',
                    'secret_key' => 'Secret Key (v3 card checkout)',
                    'webhook_secret' => 'Secret Hash / Webhook Secret',
                ],
                'secret_fields' => ['client_secret', 'secret_key', 'webhook_secret'],
            ],
            'paystack' => [
                'key' => 'paystack',
                'name' => 'Paystack',
                'brand_color' => '#09a5db',
                'logo_path' => 'images/payment-gateways/paystack.svg',
                'region' => 'Africa',
                'currencies' => ['NGN', 'GHS', 'ZAR', 'KES', 'USD'],
                'status' => 'planned',
                'summary' => 'Vendor-ready for card, transfer, USSD, and mobile-money checkout where Paystack is available.',
                'webhook_header' => 'x-paystack-signature',
                'webhook_note' => 'Set this endpoint in Paystack webhooks and listen for charge.success events.',
                'fields' => [
                    'public_key' => 'Public Key',
                    'secret_key' => 'Secret Key',
                ],
                'secret_fields' => ['secret_key'],
            ],
            'monnify' => [
                'key' => 'monnify',
                'name' => 'Monnify',
                'brand_color' => '#2f2f8f',
                'logo_path' => 'images/payment-gateways/monnify.svg',
                'region' => 'Nigeria',
                'currencies' => ['NGN'],
                'status' => 'planned',
                'summary' => 'Vendor-ready for reserved accounts, transfer collection, USSD, and card checkout in Nigeria.',
                'webhook_header' => 'monnify-signature',
                'webhook_note' => 'Set this endpoint in Monnify webhook settings for transaction completion events.',
                'fields' => [
                    'public_key' => 'Public Key',
                    'secret_key' => 'Secret Key',
                    'contract_code' => 'Contract Code',
                ],
                'secret_fields' => ['secret_key'],
            ],
            'squad' => [
                'key' => 'squad',
                'name' => 'Squad',
                'brand_color' => '#ed1c24',
                'logo_path' => 'images/payment-gateways/squad.svg',
                'region' => 'Nigeria',
                'currencies' => ['NGN'],
                'status' => 'planned',
                'summary' => 'Vendor-ready for GTCO Squad collections and local Nigerian settlement.',
                'webhook_header' => 'x-squad-encrypted-body',
                'webhook_note' => 'Set this endpoint in Squad webhooks so completed transactions reconcile automatically.',
                'fields' => [
                    'public_key' => 'Public Key',
                    'secret_key' => 'Secret Key',
                ],
                'secret_fields' => ['secret_key'],
            ],
            'manual_bank' => [
                'key' => 'manual_bank',
                'name' => 'Manual bank transfer',
                'brand_color' => '#18181b',
                'logo_path' => null,
                'region' => 'Offline',
                'currencies' => ['NGN'],
                'status' => 'planned',
                'summary' => 'Vendor-ready for admin-confirmed offline transfer where online checkout is not required.',
                'webhook_header' => null,
                'webhook_note' => 'Manual transfer does not need a provider webhook. Admin confirmation will be required.',
                'fields' => [
                    'bank_name' => 'Bank Name',
                    'account_name' => 'Account Name',
                    'account_number' => 'Account Number',
                ],
                'secret_fields' => [],
            ],
        ];
    }

    public static function gateway(string $key): array
    {
        return self::onlineGateways()[$key] ?? self::onlineGateways()[self::FLUTTERWAVE];
    }

    public static function gatewayCards(): array
    {
        return collect(self::onlineGateways())
            ->map(function (array $gateway): array {
                $gateway['logo_url'] = self::gatewayLogoUrl($gateway['key']);
                $gateway['currency_label'] = implode(', ', $gateway['currencies']);
                $gateway['status_label'] = $gateway['status'] === 'live' ? 'Live adapter' : 'Adapter pending';

                return $gateway;
            })
            ->all();
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

    public static function gatewayLogoUrl(?string $gateway): string
    {
        $path = self::gateway($gateway ?: self::FLUTTERWAVE)['logo_path'] ?? null;

        return $path && file_exists(public_path($path))
            ? asset($path)
            : '';
    }

    public static function credentialFields(?string $gateway): array
    {
        return (array) (self::gateway($gateway ?: self::FLUTTERWAVE)['fields'] ?? []);
    }

    public static function secretFieldKeys(?string $gateway): array
    {
        return (array) (self::gateway($gateway ?: self::FLUTTERWAVE)['secret_fields'] ?? []);
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
            'logo_url' => self::gatewayLogoUrl($gateway['key']),
            'brand_color' => $gateway['brand_color'],
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
            'logo_url' => self::gatewayLogoUrl($gateway['key']),
            'brand_color' => $gateway['brand_color'],
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
        if ($shop->paymentGateway() !== self::FLUTTERWAVE) {
            $settings = $shop->paymentGatewaySettings();

            return collect(self::credentialFields($shop->paymentGateway()))
                ->map(function (string $label, string $key) use ($settings): array {
                    return [
                        'label' => $label,
                        'ready_badge' => $label.' saved',
                        'missing_badge' => $label.' missing',
                        'ready' => filled($settings[$key] ?? null),
                        'hint' => filled($settings[$key] ?? null)
                            ? 'Saved securely for this gateway'
                            : 'Required before this gateway adapter can be activated',
                    ];
                })
                ->all();
        }

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
