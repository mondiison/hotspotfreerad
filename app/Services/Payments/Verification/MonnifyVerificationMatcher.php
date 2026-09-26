<?php

namespace App\Services\Payments\Verification;

/**
 * Whether a Monnify verify response actually represents this exact,
 * successful payment -- the response field names don't change based on
 * whether the money charged was a hotspot customer (Payment) or a tenant
 * paying their platform subscription (PlatformBillingPayment), only the
 * {reference, currency, amount} being checked against does. Previously
 * duplicated as HotspotPaymentConfirmationService::monnifyVerificationMatchesPayment()
 * and PlatformBillingConfirmationService's inline Monnify branch.
 */
class MonnifyVerificationMatcher
{
    public static function matches(array $verification, string $reference, string $currency, float $amount): bool
    {
        return data_get($verification, 'requestSuccessful') === true
            && self::statusIsSuccessful(data_get($verification, 'responseBody.paymentStatus'))
            && data_get($verification, 'responseBody.paymentReference') === $reference
            && strtoupper((string) data_get($verification, 'responseBody.currency')) === strtoupper($currency)
            && (float) data_get($verification, 'responseBody.amountPaid') >= $amount;
    }

    private static function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed', 'paid'], true);
    }
}
