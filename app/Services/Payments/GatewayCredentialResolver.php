<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\PlatformPaymentSettingsService;

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
        $settings = $payment->shop?->tenant?->wallet_enabled
            ? $this->platformSettings->gatewaySettings($gateway)
            : (array) ($payment->shop?->paymentGatewaySettings()[$gateway] ?? []);

        return new GatewayCredentials($settings);
    }
}
