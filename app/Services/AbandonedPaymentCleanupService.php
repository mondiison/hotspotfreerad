<?php

namespace App\Services;

use App\Models\Payment;
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
     * @return array{candidates:int, abandoned:int, recovered:int, skipped:int}
     */
    public function run(int $olderThanMinutes = 60, bool $dryRun = false): array
    {
        $candidates = Payment::query()
            ->with(['shop.tenant', 'package', 'customer'])
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('provider_reference')->orWhere('provider_reference', ''))
            ->where('created_at', '<=', now()->subMinutes(max(1, $olderThanMinutes)))
            ->get();

        $result = ['candidates' => $candidates->count(), 'abandoned' => 0, 'recovered' => 0, 'skipped' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($candidates as $payment) {
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
                        $result['skipped']++;

                        continue;
                    }

                    $verification = null;
                }

                if ($verification && $this->confirmation->verificationMatchesPayment($verification, $payment)) {
                    $this->confirmation->markPaidAndGrantAccess($payment, $verification);
                    $result['recovered']++;

                    continue;
                }
            }

            $payment->update([
                'status' => self::STATUS,
                'payload' => array_merge($payment->payload ?? [], [
                    'abandoned_at' => now()->toIso8601String(),
                    'abandoned_reason' => 'Checkout never reached the payment gateway (no provider reference).',
                ]),
            ]);
            $result['abandoned']++;
        }

        return $result;
    }

    private function isStandardCardCheckout(Payment $payment): bool
    {
        return data_get($payment->payload, 'flutterwave_checkout_version') === 'standard_v3';
    }
}
