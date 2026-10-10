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
            $tenant = $payment->shop->tenant;
            $fields = $this->platformSettings->gatewaySettings($gateway);

            // 2026-10-10: a tenant piloting automated subaccount
            // settlement (Shop::paymentGateway()'s per-tenant override)
            // needs its subaccount code riding along with the platform's
            // own credentials, so the gateway class can split the charge.
            // Every other wallet tenant's subaccount_settlement_gateway
            // is null, so this is a no-op for them -- confirmed no
            // behavior change for anyone not explicitly opted in.
            if ($tenant->subaccount_settlement_gateway === $gateway && filled($tenant->subaccount_code)) {
                $fields['subaccount_code'] = $tenant->subaccount_code;
            }

            return new GatewayCredentials($fields);
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

        $fields = (array) ($payment->shop?->paymentGatewaySettings()[$gateway] ?? []);
        $tenant = $payment->shop?->tenant;

        // 2026-10-10: the mirror image of the wallet-mode merge above --
        // here the TENANT is their own main gateway account (these are
        // their own credentials, just resolved above) and the PLATFORM is
        // registered as a subaccount ON it, for a tenant who still pays a
        // commission despite using their own gateway (see
        // TenantManagementService::createCommissionSubaccount()). Every
        // tenant without commission_subaccount_gateway set (every tenant
        // today) hits this as a no-op -- confirmed no behavior change for
        // anyone not explicitly opted in.
        if ($tenant?->commission_subaccount_gateway === $gateway && filled($tenant->commission_subaccount_code)) {
            $fields['subaccount_code'] = $tenant->commission_subaccount_code;
        }

        return new GatewayCredentials($fields);
    }
}
