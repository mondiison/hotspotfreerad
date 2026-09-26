<?php

namespace App\Services\Payments\Verification;

/**
 * Whether a Squad verify response actually represents this exact, successful
 * payment -- see MonnifyVerificationMatcher's docblock for why this is
 * shared between the tenant and platform confirmation services. Squad's own
 * field names throughout: top-level "success" boolean, data.transaction_status/
 * transaction_ref/transaction_amount (kobo). Squad's currency field is
 * genuinely optional in some responses, so a blank value is treated as a
 * non-mismatch rather than a hard failure.
 */
class SquadVerificationMatcher
{
    public static function matches(array $verification, string $reference, string $currency, float $amount): bool
    {
        $responseCurrency = data_get($verification, 'data.currency') ?: data_get($verification, 'data.currency_id');

        return data_get($verification, 'success') === true
            && self::statusIsSuccessful(data_get($verification, 'data.transaction_status'))
            && data_get($verification, 'data.transaction_ref') === $reference
            && (blank($responseCurrency) || strtoupper((string) $responseCurrency) === strtoupper($currency))
            && ((float) data_get($verification, 'data.transaction_amount') / 100) >= $amount;
    }

    private static function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed', 'paid'], true);
    }
}
