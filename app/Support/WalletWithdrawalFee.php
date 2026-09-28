<?php

namespace App\Support;

/**
 * Computes the platform's withdrawal fee (real bank-transfer charges the
 * super admin passes through) for a requested wallet withdrawal. The
 * requested amount is always the NET figure the tenant actually wants
 * paid into their bank account -- the fee is added on top and debited
 * from the wallet alongside it, never subtracted from the requested
 * amount itself, so a tenant asking to withdraw 450 always receives
 * exactly 450 (the fee is the platform's own separate charge, not a cut
 * of what the tenant asked for).
 */
class WalletWithdrawalFee
{
    /**
     * @param  array{type?: string, value?: float}  $setting  'fixed' charges a flat naira amount regardless of the requested amount; 'percentage' scales with it.
     * @return array{fee_amount: float, net_amount: float, gross_amount: float}
     */
    public static function calculate(float $requestedAmount, array $setting): array
    {
        $type = $setting['type'] ?? 'fixed';
        $value = (float) ($setting['value'] ?? 0);

        $fee = $type === 'percentage'
            ? round($requestedAmount * ($value / 100), 2)
            : round($value, 2);

        $fee = max(0.0, $fee);
        $net = round($requestedAmount, 2);

        return [
            'fee_amount' => $fee,
            'net_amount' => $net,
            'gross_amount' => round($net + $fee, 2),
        ];
    }
}
