<?php

namespace App\Services\Payments;

/**
 * What a hosted-checkout gateway needs to start a charge, independent of
 * whether the money is a hotspot customer paying for access (Payment) or a
 * tenant paying their HotspotFreeRAD subscription (PlatformBillingPayment).
 * The caller (HotspotHostedCheckoutManager / PlatformHostedCheckoutManager)
 * builds this from whichever model it actually has -- the gateway class
 * itself never needs to know which.
 */
final class ChargeRequest
{
    /**
     * @param  array<string, mixed>  $meta  passed straight through to the gateway's metadata/meta field
     */
    public function __construct(
        public readonly string $reference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $redirectUrl,
        public readonly string $customerEmail,
        public readonly string $customerName,
        public readonly string $description,
        public readonly array $meta = [],
    ) {}
}
