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
}
