<?php

namespace App\Services\Payments\Contracts;

use App\Models\Payment;

interface HotspotHostedGateway
{
    public function initializeCheckout(Payment $payment, array $customer, string $callbackUrl): array;

    public function isConfiguredFor(Payment $payment): bool;

    public function credentialSource(Payment $payment): array;
}
