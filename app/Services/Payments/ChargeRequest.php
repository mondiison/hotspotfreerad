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
     *
     * customerPhone/address* only exist for Flutterwave's v4 orchestration
     * API, which needs a full customer.phone/customer.address block --
     * Monnify/Paystack/Squad's initializeCheckout() ignores them entirely.
     * cancelUrl/productName only exist for Stripe, whose Checkout Session
     * API needs a separate cancel_url (every other gateway has just one
     * redirect URL) and a bare product name distinct from the longer
     * `description` line-item field the other gateways already read.
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
        public readonly string $customerPhone = '',
        public readonly string $addressCity = '',
        public readonly string $addressState = '',
        public readonly string $addressLine1 = '',
        public readonly string $cancelUrl = '',
        public readonly string $productName = '',
    ) {}
}
