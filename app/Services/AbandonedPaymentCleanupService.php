<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Closes hotspot checkouts that were started but never reached the gateway:
 * status still "pending" and no provider_reference long after the customer
 * left. These cannot be verified by the normal retry job (it needs a
 * reference), so they sat as "pending" forever and cluttered Payments.
 *
 * Flutterwave v3 card checkouts are the exception: their reference only
 * arrives on the redirect/webhook, so before abandoning one we look it up by
 * tx_ref and grant access if it was actually paid.
 */
class AbandonedPaymentCleanupService
{
    public const STATUS = 'abandoned';

    public function __construct(
        private readonly FlutterwaveService $flutterwave,
        private readonly HotspotPaymentConfirmationService $confirmation,
    ) {}

    /**
     * Pending payments with no provider reference, optionally narrowed by a
     * caller's own scope (the Payments page passes the signed-in user's
     * tenant scope).
     */
    public function candidates(?Builder $query = null, int $olderThanMinutes = 0): Builder
    {
        return ($query ?? Payment::query())
            ->with(['shop.tenant', 'package', 'customer'])
            ->where('status', 'pending')
            ->where(fn ($inner) => $inner->whereNull('provider_reference')->orWhere('provider_reference', ''))
            ->when($olderThanMinutes > 0, fn ($inner) => $inner->where('created_at', '<=', now()->subMinutes($olderThanMinutes)));
    }

    /**
     * @return array{candidates:int, abandoned:int, recovered:int, skipped:int}
     */
    public function run(int $olderThanMinutes = 60, bool $dryRun = false, ?Builder $query = null): array
    {
        $candidates = $this->candidates($query, max(1, $olderThanMinutes))->get();

        $result = ['candidates' => $candidates->count(), 'abandoned' => 0, 'recovered' => 0, 'skipped' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($candidates as $payment) {
            $result[$this->clean($payment)]++;
        }

        return $result;
    }

    /**
     * Close one checkout that has no reference.
     *
     * @return 'abandoned'|'recovered'|'skipped'
     */
    public function clean(Payment $payment): string
    {
        if ($payment->status !== 'pending' || filled($payment->provider_reference)) {
            return 'skipped';
        }

        if ($this->isStandardCardCheckout($payment)) {
            try {
                $verification = $this->flutterwave->verifyStandardCheckoutByTxRef($payment);
            } catch (RequestException $e) {
                // 404 = Flutterwave never saw this tx_ref, so nothing was paid.
                if ($e->response?->status() !== 404) {
                    Log::warning('Could not check abandoned Flutterwave checkout; will retry next run', [
                        'payment_id' => $payment->id,
                        'tx_ref' => $payment->tx_ref,
                        'status' => $e->response?->status(),
                    ]);

                    return 'skipped';
                }

                $verification = null;
            }

            if ($verification && $this->confirmation->verificationMatchesPayment($verification, $payment)) {
                $this->confirmation->markPaidAndGrantAccess($payment, $verification);

                return 'recovered';
            }
        }

        $payment->update([
            'status' => self::STATUS,
            'payload' => array_merge($payment->payload ?? [], [
                'abandoned_at' => now()->toIso8601String(),
                'abandoned_reason' => 'Checkout never reached the payment gateway (no provider reference).',
            ]),
        ]);

        return 'abandoned';
    }

    private function isStandardCardCheckout(Payment $payment): bool
    {
        return data_get($payment->payload, 'flutterwave_checkout_version') === 'standard_v3';
    }
}
