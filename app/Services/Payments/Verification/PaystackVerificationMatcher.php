<?php

namespace App\Services\Payments\Verification;

/**
 * Whether a Paystack verify response actually represents this exact,
 * successful payment -- see MonnifyVerificationMatcher's docblock for why
 * this is shared between the tenant and platform confirmation services.
 * Paystack's top-level "status" is a boolean (did the API call itself
 * succeed), not a status string like the generic default matcher both
 * confirmation services still use for Flutterwave/Stripe -- the real payment
 * outcome is data.status, and the amount is in kobo.
 */
class PaystackVerificationMatcher
{
    public static function matches(array $verification, string $reference, string $currency, float $amount): bool
    {
        return data_get($verification, 'status') === true
            && self::statusIsSuccessful(data_get($verification, 'data.status'))
            && data_get($verification, 'data.reference') === $reference
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($currency)
            && ((float) data_get($verification, 'data.amount') / 100) >= $amount;
    }

    private static function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed', 'paid'], true);
    }
}
