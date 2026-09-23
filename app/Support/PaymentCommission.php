<?php

namespace App\Support;

use App\Models\Shop;

class PaymentCommission
{
    /**
     * Capture a billing snapshot for a customer payment.
     */
    public static function forShop(Shop $shop, float $amount): array
    {
        $shop->loadMissing('tenant');

        $billingModel = $shop->tenant?->billing_model ?: 'subscription';
        $commissionRate = $billingModel === 'commission'
            ? (float) $shop->tenant?->commission_rate
            : 0.0;
        $platformFee = round($amount * ($commissionRate / 100), 2);

        return [
            'gross_amount' => round($amount, 2),
            'platform_fee_amount' => $platformFee,
            'tenant_net_amount' => round($amount - $platformFee, 2),
            'commission_rate' => round($commissionRate, 2),
            'billing_model' => $billingModel,
        ];
    }

    /**
     * The wallet-mode equivalent of forShop() -- used only for a tenant with
     * `wallet_enabled`, whose customer payments settle into the platform's own
     * gateway account instead of the shop's. `enable(Tenant $tenant)` on
     * WalletService already forces `billing_model = 'commission'` with
     * `commission_rate` copied from the tenant's plan, so the fee math itself
     * is identical to forShop() -- what differs is `$tenant->wallet_commission_bearer`,
     * which decides WHO is actually charged the fee at checkout:
     *
     * - 'tenant' (default): the customer pays exactly $packagePrice; the fee
     *   comes out of the tenant's share (gross == packagePrice, net == packagePrice - fee).
     * - 'customer': the customer is charged $packagePrice PLUS the fee on top,
     *   so the tenant's wallet still nets the full listed package price
     *   (gross == packagePrice + fee, net == packagePrice exactly).
     *
     * Either way `gross_amount` is always what the customer actually paid --
     * that's the number `Payment.amount`/the gateway checkout total use, and
     * the number WalletService::creditForPayment()'s first ledger line credits.
     */
    public static function forWalletCheckout(Shop $shop, float $packagePrice): array
    {
        $shop->loadMissing('tenant');

        $commissionRate = (float) $shop->tenant?->commission_rate;
        $platformFee = round($packagePrice * ($commissionRate / 100), 2);
        $bearer = $shop->tenant?->wallet_commission_bearer ?: 'tenant';

        $chargedAmount = $bearer === 'customer'
            ? round($packagePrice + $platformFee, 2)
            : round($packagePrice, 2);

        $tenantNetAmount = $bearer === 'customer'
            ? round($packagePrice, 2)
            : round($packagePrice - $platformFee, 2);

        return [
            'charged_amount' => $chargedAmount,
            'gross_amount' => $chargedAmount,
            'platform_fee_amount' => $platformFee,
            'tenant_net_amount' => $tenantNetAmount,
            'commission_rate' => round($commissionRate, 2),
            'billing_model' => 'commission',
        ];
    }
}
