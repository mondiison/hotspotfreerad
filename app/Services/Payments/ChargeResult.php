<?php

namespace App\Services\Payments;

final class ChargeResult
{
    /**
     * @param  array<string, mixed>  $response  the raw gateway response, stored as-is in the payment's payload for audit/debugging
     */
    public function __construct(
        public readonly array $response,
        public readonly ?string $providerReference,
        public readonly ?string $checkoutUrl,
    ) {}
}
