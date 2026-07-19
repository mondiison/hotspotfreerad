<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\HotspotPaymentConfirmationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyHotspotPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120, 300];

    public function __construct(
        private readonly int $paymentId,
        private readonly string $providerReference,
        private readonly string $resourceType = 'order',
    ) {
        $this->onQueue('payments');
    }

    public function handle(HotspotPaymentConfirmationService $payments): void
    {
        $payment = Payment::with(['shop.tenant', 'package', 'subscription'])->find($this->paymentId);

        if (! $payment || ($payment->status === 'successful' && $payment->subscription)) {
            return;
        }

        $payments->verifyAndGrant($payment, $this->providerReference, $this->resourceType);
    }
}
