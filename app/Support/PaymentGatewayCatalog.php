<?php

namespace App\Support;

use App\Models\Shop;
use App\Services\PlatformPaymentSettingsService;

class PaymentGatewayCatalog
{
    public const FLUTTERWAVE = 'flutterwave';
    public const PAYSTACK = 'paystack';
    public const MONNIFY = 'monnify';
    public const SQUAD = 'squad';
    public const STRIPE = 'stripe';
    public const MANUAL_BANK = 'manual_bank';

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
                'walled_garden_hosts' => ['*.flutterwave.com', '*.ravepay.co'],
            ],
            self::PAYSTACK => [
                'key' => self::PAYSTACK,
                'name' => 'Paystack',
                'brand_color' => '#09a5db',
                'logo_path' => 'images/payment-gateways/paystack.svg',
                'region' => 'Africa',
                'currencies' => ['NGN', 'GHS', 'ZAR', 'KES', 'USD'],
                'status' => 'live',
                'summary' => 'Live adapter for hosted card, transfer, USSD, and mobile-money checkout where Paystack is available.',
                'webhook_header' => 'x-paystack-signature',
                'webhook_note' => 'Set this endpoint in Paystack webhooks and listen for charge.success events.',
                'fields' => [
                    'public_key' => 'Public Key',
                    'secret_key' => 'Secret Key',
                ],
                'secret_fields' => ['secret_key'],
                'walled_garden_hosts' => ['*.paystack.com', '*.paystack.co'],
            ],
            self::MONNIFY => [
                'key' => self::MONNIFY,
                'name' => 'Monnify',
                'brand_color' => '#2f2f8f',
                'logo_path' => 'images/payment-gateways/monnify.svg',
                'region' => 'Nigeria',
                'currencies' => ['NGN'],
                'status' => 'live',
                'summary' => 'Live adapter for hosted checkout with card, account transfer, USSD, and phone-number payment in Nigeria.',
                'webhook_header' => 'monnify-signature',
                'webhook_note' => 'Set this endpoint in Monnify webhook settings for transaction completion events.',
                'fields' => [
                    'public_key' => 'API Key',
                    'secret_key' => 'Secret Key',
                    'contract_code' => 'Contract Code',
                    'environment' => 'Environment',
                ],
                'secret_fields' => ['secret_key'],
                'select_fields' => [
                    'environment' => ['test' => 'Sandbox / Test', 'live' => 'Live'],
                ],
                'walled_garden_hosts' => ['*.monnify.com'],
            ],
            self::SQUAD => [
                'key' => self::SQUAD,
                'name' => 'Squad',
                'brand_color' => '#ed1c24',
                'logo_path' => 'images/payment-gateways/squad.svg',
                'region' => 'Nigeria',
                'currencies' => ['NGN'],
                'status' => 'live',
                'summary' => 'Live adapter for hosted checkout with cards, bank transfer, and USSD through Squad by GTCO.',
                'webhook_header' => 'x-squad-encrypted-body',
                'webhook_note' => 'Set this endpoint in Squad webhooks so completed transactions reconcile automatically.',
                'fields' => [
                    'public_key' => 'Public Key',
                    'secret_key' => 'Secret Key',
                    'environment' => 'Environment',
                ],
                'secret_fields' => ['secret_key'],
                'select_fields' => [
                    'environment' => ['test' => 'Sandbox / Test', 'live' => 'Live'],
                ],
                'walled_garden_hosts' => ['*.squadco.com'],
            ],
            self::STRIPE => [
                'key' => self::STRIPE,
                'name' => 'Stripe',
                'brand_color' => '#635bff',
                'logo_path' => 'images/payment-gateways/stripe.svg',
                'region' => 'Global',
                'currencies' => ['NGN', 'USD', 'GBP', 'EUR', 'CAD', 'AUD'],
                'status' => 'live',
                'summary' => 'Live adapter for Stripe hosted Checkout with cards, wallets, and supported local methods.',
                'webhook_header' => 'stripe-signature',
                'webhook_note' => 'Use the Stripe signing secret that starts with whsec_ for this webhook endpoint.',
                'fields' => [
                    'publishable_key' => 'Publishable Key',
                    'secret_key' => 'Secret Key',
                    'webhook_secret' => 'Webhook Signing Secret',
                ],
                'secret_fields' => ['secret_key', 'webhook_secret'],
                'walled_garden_hosts' => ['*.stripe.com', '*.stripe.network'],
            ],
            self::MANUAL_BANK => [
                'key' => self::MANUAL_BANK,
                'name' => 'Manual bank transfer',
                'brand_color' => '#18181b',
                'logo_path' => null,
                'region' => 'Offline',
                'currencies' => ['NGN'],
                'status' => 'live',
                'summary' => 'Live adapter for admin-confirmed offline transfer where online checkout is not required.',
                'webhook_header' => null,
                'webhook_note' => 'Manual transfer does not need a provider webhook. Admin confirmation will be required.',
                'fields' => [
                    'bank_name' => 'Bank Name',
                    'account_name' => 'Account Name',
                    'account_number' => 'Account Number',
                    'instructions' => 'Customer Instructions',
                ],
                'secret_fields' => [],
                'walled_garden_hosts' => [],
            ],
        ];
    }

    /**
     * External hosts a hotspot customer's browser must reach to complete
     * checkout on this gateway's hosted payment page, BEFORE the device is
     * RADIUS-authenticated -- these need `/ip hotspot walled-garden` entries
     * on the router or the customer can never reach the payment page at all.
     * Best-effort based on each provider's known public checkout domains;
     * not verified against a live checkout flow from this codebase, so treat
     * as a starting point and confirm against real traffic if a customer
     * reports being unable to reach checkout.
     *
     * @return list<string>
     */
    public static function walledGardenHosts(?string $gatewayKey): array
    {
        return (array) (self::gateway($gatewayKey ?: self::FLUTTERWAVE)['walled_garden_hosts'] ?? []);
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

    /**
     * Fields that render as a fixed choice (e.g. Environment: Live/Test)
     * instead of a free-text credential input. Rare -- most gateways have
     * none of these; `PaymentSettingsCard` renders any field listed here as
     * a select using its option map instead of a text box.
     *
     * @return array<string, array<string, string>> field key => (option value => option label)
     */
    public static function selectFields(?string $gateway): array
    {
        return (array) (self::gateway($gateway ?: self::FLUTTERWAVE)['select_fields'] ?? []);
    }

    /**
     * Unlike Squad/Monnify (genuinely different API base URLs per environment,
     * so `environment` is a real, editable `select_fields` choice), Paystack
     * and Stripe use exactly ONE API host for both modes -- the secret key
     * itself is the only thing that determines live vs test, via the
     * `sk_test_`/`sk_live_` prefix convention both providers share. Making
     * this an independently-editable setting for those two would let it
     * drift out of sync with what's actually saved (pick "Live" but the key
     * is still a test key, or vice versa) -- a setting that can lie. This
     * derives the true answer straight from the key instead, so the UI can
     * show it as a read-only fact rather than a second, possibly-wrong
     * source of truth. Returns null when the key is blank or doesn't match
     * either known prefix.
     */
    public static function detectedEnvironment(?string $secretKey): ?string
    {
        return match (true) {
            str_starts_with((string) $secretKey, 'sk_test_') => 'test',
            str_starts_with((string) $secretKey, 'sk_live_') => 'live',
            default => null,
        };
    }

    public static function implementedGatewayKeys(): array
    {
        return [self::FLUTTERWAVE, self::PAYSTACK, self::MONNIFY, self::SQUAD, self::STRIPE, self::MANUAL_BANK];
    }

    public static function tenantProvider(?string $gatewayKey = null): array
    {
        $gatewayKey = $gatewayKey ?: self::FLUTTERWAVE;
        $gateway = self::onlineGateways()[$gatewayKey] ?? self::onlineGateways()[self::FLUTTERWAVE];
        $channels = [
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
        ];

        if ($gatewayKey === self::PAYSTACK) {
            $channels = [
                'checkout' => [
                    'label' => 'Hosted checkout',
                    'requires' => 'Paystack Secret Key',
                    'description' => 'Used to redirect hotspot customers to Paystack checkout for card, transfer, USSD, and supported local channels.',
                ],
                'webhook' => [
                    'label' => 'Webhook confirmation',
                    'requires' => 'x-paystack-signature validation with Secret Key',
                    'description' => 'Allows Paystack to confirm completed payments even if the customer closes the browser.',
                ],
            ];
        }

        if ($gatewayKey === self::MONNIFY) {
            $channels = [
                'checkout' => [
                    'label' => 'Hosted checkout',
                    'requires' => 'Monnify API Key + Secret Key + Contract Code',
                    'description' => 'Used to redirect hotspot customers to Monnify checkout for card, account transfer, USSD, and phone-number payment.',
                ],
                'webhook' => [
                    'label' => 'Webhook confirmation',
                    'requires' => 'monnify-signature validation with Secret Key',
                    'description' => 'Allows Monnify to confirm completed payments even if the customer closes the browser.',
                ],
            ];
        }

        if ($gatewayKey === self::SQUAD) {
            $channels = [
                'checkout' => [
                    'label' => 'Hosted checkout',
                    'requires' => 'Squad Secret Key',
                    'description' => 'Used to redirect hotspot customers to Squad checkout for card, bank transfer, and USSD payments.',
                ],
                'webhook' => [
                    'label' => 'Webhook confirmation',
                    'requires' => 'x-squad-encrypted-body validation with Secret Key',
                    'description' => 'Allows Squad to confirm completed payments even if the customer closes the browser.',
                ],
            ];
        }

        if ($gatewayKey === self::STRIPE) {
            $channels = [
                'checkout' => [
                    'label' => 'Hosted Checkout',
                    'requires' => 'Stripe Secret Key',
                    'description' => 'Used to redirect hotspot customers to Stripe Checkout for card, wallet, and supported local payment methods.',
                ],
                'webhook' => [
                    'label' => 'Webhook confirmation',
                    'requires' => 'stripe-signature validation with webhook signing secret',
                    'description' => 'Allows Stripe to confirm completed payments even if the customer closes the browser.',
                ],
            ];
        }

        if ($gatewayKey === self::MANUAL_BANK) {
            $channels = [
                'instructions' => [
                    'label' => 'Bank details',
                    'requires' => 'Bank name + account name + account number',
                    'description' => 'Shown to customers on the captive portal when online checkout is not required.',
                ],
                'confirmation' => [
                    'label' => 'Admin confirmation',
                    'requires' => 'Tenant admin verifies the bank alert or statement',
                    'description' => 'Access is provisioned only after an admin confirms the pending payment.',
                ],
            ];
        }

        return [
            'key' => $gateway['key'],
            'name' => $gateway['name'],
            'logo_url' => self::gatewayLogoUrl($gateway['key']),
            'brand_color' => $gateway['brand_color'],
            'label' => 'Default tenant gateway',
            'summary' => 'Customer hotspot payments settle into the payment account configured for each tenant shop.',
            'status' => $gateway['status'],
            'channels' => $channels,
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
            $selectFieldKeys = array_keys(self::selectFields($shop->paymentGateway()));

            return collect(self::credentialFields($shop->paymentGateway()))
                ->reject(fn (string $label, string $key): bool => in_array($key, $selectFieldKeys, true))
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
            self::FLUTTERWAVE => 'Flutterwave',
            self::PAYSTACK => 'Paystack',
            self::MONNIFY => 'Monnify',
            self::SQUAD => 'Squad',
            self::STRIPE => 'Stripe',
            self::MANUAL_BANK => 'Manual bank transfer',
            'voucher_cash' => 'Voucher cash',
        ];
    }

    public static function providerLabel(?string $provider): string
    {
        return self::paymentMethods()[$provider] ?? str((string) $provider)->replace('_', ' ')->headline()->toString();
    }
}
