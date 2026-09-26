<?php

namespace App\Services\Payments\Verification;

/**
 * Whether a Stripe verify response (a Checkout Session object) actually
 * represents this exact, successful payment -- see MonnifyVerificationMatcher's
 * docblock for why this is shared between the tenant and platform confirmation
 * services. Stripe's own field names: object === "checkout.session",
 * payment_status, client_reference_id (or metadata.payment_reference as a
 * fallback -- platform billing's own checkout never set client_reference_id
 * on some older payments), currency, amount_total in cents.
 */
class StripeVerificationMatcher
{
    public static function matches(array $verification, string $reference, string $currency, float $amount): bool
    {
        return data_get($verification, 'object') === 'checkout.session'
            && self::statusIsSuccessful(data_get($verification, 'payment_status'))
            && (data_get($verification, 'client_reference_id') === $reference
                || data_get($verification, 'metadata.payment_reference') === $reference)
            && strtoupper((string) data_get($verification, 'currency')) === strtoupper($currency)
            && ((float) data_get($verification, 'amount_total') / 100) >= $amount;
    }

    private static function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed', 'paid'], true);
    }
}
