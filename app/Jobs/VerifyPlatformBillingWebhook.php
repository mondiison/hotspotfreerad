<?php

namespace App\Jobs;

use App\Models\PlatformBillingPayment;
use App\Services\PlatformBillingConfirmationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyPlatformBillingWebhook implements ShouldQueue
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

    public function handle(PlatformBillingConfirmationService $billing): void
    {
        $payment = PlatformBillingPayment::with(['tenant', 'billingPlan'])->find($this->paymentId);

        if (! $payment || ($payment->status === 'successful' && $payment->tenant_billing_subscription_id)) {
            return;
        }

        $billing->verifyAndActivate($payment, $this->providerReference, $this->resourceType);
    }
}
