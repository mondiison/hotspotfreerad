<?php

namespace App\Services;

use App\Models\PlatformBillingPayment;
use App\Models\TenantBillingSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformBillingConfirmationService
{
    public function __construct(
        private readonly PlatformFlutterwaveService $flutterwave,
    ) {}

    public function verifyAndActivate(PlatformBillingPayment $payment, string $providerReference, string $resourceType = 'order'): bool
    {
        $payment->loadMissing(['tenant', 'billingPlan']);

        if ($payment->status === 'successful' && $payment->tenant_billing_subscription_id) {
            return true;
        }

        $verification = $this->flutterwave->verifyPayment($providerReference, $resourceType);

        if (! $this->verificationMatchesPayment($verification, $payment)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            Log::warning('Flutterwave verification did not match platform billing payment', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
            ]);

            return false;
        }

        $this->activateSubscription($payment, $verification);

        return true;
    }

    public function activateSubscription(PlatformBillingPayment $payment, array $verification): void
    {
        DB::transaction(function () use ($payment, $verification): void {
            $payment->refresh();

            if ($payment->status === 'successful' && $payment->tenant_billing_subscription_id) {
                return;
            }

            $subscription = TenantBillingSubscription::create([
                'tenant_id' => $payment->tenant_id,
                'billing_plan_id' => $payment->billing_plan_id,
                'status' => 'active',
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'current_period_starts_at' => now(),
                'current_period_ends_at' => now()->addMonth(),
                'provider' => $payment->provider,
                'provider_reference' => (string) (data_get($verification, 'data.id') ?: $payment->provider_reference),
                'payload' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                ],
            ]);

            $payment->update([
                'tenant_billing_subscription_id' => $subscription->id,
                'status' => 'successful',
                'provider_reference' => (string) (data_get($verification, 'data.id') ?: $payment->provider_reference),
                'paid_at' => now(),
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);
        });
    }

    public function verificationMatchesPayment(array $verification, PlatformBillingPayment $payment): bool
    {
        return in_array(strtolower((string) data_get($verification, 'status')), ['success', 'successful', 'succeeded'], true)
            && $this->statusIsSuccessful(data_get($verification, 'data.status'))
            && (data_get($verification, 'data.reference') === $payment->tx_ref || data_get($verification, 'data.tx_ref') === $payment->tx_ref)
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($payment->currency)
            && (float) data_get($verification, 'data.amount') >= (float) $payment->amount;
    }

    private function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed'], true);
    }
}
