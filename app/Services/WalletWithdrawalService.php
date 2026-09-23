<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletWithdrawalService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * Reserves the requested amount immediately (debits the wallet as soon as
     * the request is made, not when it's eventually paid out) -- this is what
     * stops two withdrawal requests submitted back to back from both passing
     * a "sufficient balance" check and overdrawing the wallet between them.
     * reject() reverses this reservation; markPaid() makes no further balance
     * change, since the funds were already set aside at request time.
     */
    public function request(Tenant $tenant, float $amount, array $bankDetails, User $requestedBy): WalletWithdrawal
    {
        return DB::transaction(function () use ($tenant, $amount, $bankDetails, $requestedBy): WalletWithdrawal {
            $wallet = Wallet::query()->lockForUpdate()->where('tenant_id', $tenant->id)->first();

            if (! $wallet || $amount <= 0 || $amount > (float) $wallet->balance) {
                throw ValidationException::withMessages([
                    'amount' => 'You can only withdraw up to your current wallet balance.',
                ]);
            }

            $withdrawal = WalletWithdrawal::create([
                'tenant_id' => $tenant->id,
                'wallet_id' => $wallet->id,
                'amount' => round($amount, 2),
                'bank_name' => (string) ($bankDetails['bank_name'] ?? ''),
                'account_number' => (string) ($bankDetails['account_number'] ?? ''),
                'account_name' => (string) ($bankDetails['account_name'] ?? ''),
                'status' => 'pending',
                'requested_by' => $requestedBy->id,
            ]);

            $this->wallets->applyEntry(
                $wallet,
                'debit',
                (float) $withdrawal->amount,
                'Withdrawal requested — '.$withdrawal->bank_name.' '.$withdrawal->account_number,
                withdrawalId: $withdrawal->id,
            );

            return $withdrawal;
        });
    }

    public function reject(WalletWithdrawal $withdrawal, User $admin, ?string $notes = null): void
    {
        DB::transaction(function () use ($withdrawal, $admin, $notes): void {
            if ($withdrawal->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending withdrawal request can be rejected.',
                ]);
            }

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($withdrawal->wallet_id);

            $this->wallets->applyEntry(
                $wallet,
                'credit',
                (float) $withdrawal->amount,
                'Withdrawal request rejected — funds returned to wallet',
                withdrawalId: $withdrawal->id,
            );

            $withdrawal->forceFill([
                'status' => 'rejected',
                'admin_notes' => $notes,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ])->save();
        });
    }

    public function approve(WalletWithdrawal $withdrawal, User $admin, ?string $notes = null): void
    {
        if ($withdrawal->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only a pending withdrawal request can be approved.',
            ]);
        }

        $withdrawal->forceFill([
            'status' => 'approved',
            'admin_notes' => $notes,
            'processed_by' => $admin->id,
        ])->save();
    }

    /**
     * The fully-manual fulfillment step -- the admin has already sent the
     * money outside the app (a real bank transfer) before clicking this. No
     * further balance change happens here; the funds were already reserved
     * off the wallet balance back when the request was made.
     */
    public function markPaid(WalletWithdrawal $withdrawal, User $admin, ?string $notes = null): void
    {
        if (! in_array($withdrawal->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only a pending or approved withdrawal request can be marked paid.',
            ]);
        }

        $withdrawal->forceFill([
            'status' => 'paid',
            'admin_notes' => $notes ?? $withdrawal->admin_notes,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ])->save();
    }
}
