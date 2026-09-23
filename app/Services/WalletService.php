<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Turns wallet mode on for a tenant. Callers must run
     * BillingPlanLimits::assertCanEnableWallet($user) first -- this method
     * itself doesn't re-check plan eligibility, matching how
     * BillingPlanLimits::assertCanCreateShop()/etc. are always called before
     * their respective create actions rather than duplicated inside them.
     *
     * Force-sets billing_model/commission_rate from the tenant's own plan so
     * PaymentCommission::forShop()/forWalletCheckout() need no separate
     * "is this tenant in wallet mode" branch of their own -- a wallet-enabled
     * tenant simply *is* a commission-billing tenant under the hood, with the
     * rate coming from the plan tier rather than being hand-set.
     */
    public function enable(Tenant $tenant): Wallet
    {
        $plan = $tenant->currentBillingSubscription?->billingPlan;

        $tenant->forceFill([
            'wallet_enabled' => true,
            'billing_model' => 'commission',
            'commission_rate' => $plan?->wallet_commission_rate ?? 0,
        ])->save();

        return Wallet::query()->firstOrCreate(['tenant_id' => $tenant->id]);
    }

    public function disable(Tenant $tenant): void
    {
        $tenant->forceFill(['wallet_enabled' => false])->save();
    }

    public function balance(Tenant $tenant): float
    {
        return (float) ($tenant->wallet?->balance ?? 0);
    }

    /**
     * Credits a wallet for a successful wallet-mode payment. Writes TWO
     * ledger rows, bank-statement style, rather than one opaque net credit:
     * a credit for the full amount the customer actually paid
     * ($payment->gross_amount), then -- only when there's actually a fee --
     * a separate debit for the platform's commission
     * ($payment->platform_fee_amount). The wallet balance after both rows
     * always equals $payment->tenant_net_amount, exactly like a single net
     * credit would produce, but the two-line trail is what shows up in the
     * tenant's transaction history.
     */
    public function creditForPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $wallet = Wallet::query()->lockForUpdate()->firstOrCreate(['tenant_id' => $payment->shop->tenant_id]);

            $wallet = $this->applyEntry(
                $wallet,
                'credit',
                (float) $payment->gross_amount,
                'Payment received — '.$payment->package->name,
                paymentId: $payment->id,
            );

            $fee = (float) $payment->platform_fee_amount;

            if ($fee > 0) {
                $this->applyEntry(
                    $wallet,
                    'debit',
                    $fee,
                    'Platform commission ('.rtrim(rtrim(number_format((float) $payment->commission_rate, 2), '0'), '.').'%)',
                    paymentId: $payment->id,
                );
            }
        });
    }

    /**
     * Writes one ledger row and updates the wallet's running balance,
     * returning the refreshed wallet so callers can chain further entries
     * against the up-to-date balance within the same DB transaction.
     */
    public function applyEntry(Wallet $wallet, string $type, float $amount, string $description, ?int $paymentId = null, ?int $withdrawalId = null): Wallet
    {
        $balanceAfter = $type === 'credit'
            ? round((float) $wallet->balance + $amount, 2)
            : round((float) $wallet->balance - $amount, 2);

        $wallet->forceFill(['balance' => $balanceAfter])->save();

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'payment_id' => $paymentId,
            'wallet_withdrawal_id' => $withdrawalId,
            'type' => $type,
            'amount' => round($amount, 2),
            'balance_after' => $balanceAfter,
            'description' => $description,
        ]);

        return $wallet->refresh();
    }
}
