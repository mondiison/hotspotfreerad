<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\PlatformPaymentSettingsService;
use App\Support\PaymentGatewayCatalog;

/**
 * Resolves which credentials a tenant hotspot Payment should use for a given
 * gateway -- the shop's own saved settings, or (for a wallet-enabled tenant)
 * the platform's own settings instead. This was previously duplicated inside
 * each tenant-facing gateway service's own private setting()/secretKey()
 * method, each independently deciding whether to check the shop's
 * tenant->wallet_enabled flag -- Paystack/Squad never gained that branch at
 * all. Both HotspotHostedCheckoutManager and HotspotPaymentConfirmationService
 * need the exact same resolution for the same gateway, so it lives here once.
 */
class GatewayCredentialResolver
{
    public function __construct(private readonly PlatformPaymentSettingsService $platformSettings) {}

    public function forPayment(Payment $payment, string $gateway): GatewayCredentials
    {
        if ($payment->shop?->tenant?->wallet_enabled) {
            return new GatewayCredentials($this->platformSettings->gatewaySettings($gateway));
        }

        // Flutterwave is the odd one out: its credentials live in four
        // dedicated encrypted Shop columns (flutterwave_client_id/client_secret/
        // secret_key/webhook_secret), not the generic payment_gateway_settings
        // JSON blob Monnify/Paystack/Squad use -- normalize it into the same
        // shape here rather than teaching every caller about this difference.
        if ($gateway === PaymentGatewayCatalog::FLUTTERWAVE) {
            return new GatewayCredentials([
                'client_id' => $payment->shop?->flutterwave_client_id,
                'client_secret' => $payment->shop?->flutterwave_client_secret,
                'secret_key' => $payment->shop?->flutterwave_secret_key,
                'webhook_secret' => $payment->shop?->flutterwave_webhook_secret,
            ]);
        }

        return new GatewayCredentials((array) ($payment->shop?->paymentGatewaySettings()[$gateway] ?? []));
    }
}
