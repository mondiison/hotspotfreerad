<?php

namespace App\Services;

use App\Models\PlatformBillingPayment;
use App\Models\TenantBillingSubscription;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\Verification\MonnifyVerificationMatcher;
use App\Services\Payments\Verification\PaystackVerificationMatcher;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformBillingConfirmationService
{
    public function __construct(
        private readonly PlatformFlutterwaveService $flutterwave,
        private readonly PlatformStripeService $stripe,
        private readonly PlatformSquadService $squad,
        private readonly PlatformPaymentSettingsService $settings,
        private readonly MonnifyGateway $monnifyGateway,
        private readonly PaystackGateway $paystackGateway,
    ) {}

    public function verifyAndActivate(PlatformBillingPayment $payment, string $providerReference, string $resourceType = 'order'): bool
    {
        $payment->loadMissing(['tenant', 'billingPlan']);

        if ($payment->status === 'successful' && $payment->tenant_billing_subscription_id) {
            return true;
        }

        $verification = match ($payment->provider) {
            PaymentGatewayCatalog::STRIPE => $this->stripe->verifyPayment($providerReference),
            PaymentGatewayCatalog::MONNIFY => $this->monnifyGateway->verifyPayment($this->credentialsFor(PaymentGatewayCatalog::MONNIFY), $providerReference),
            PaymentGatewayCatalog::PAYSTACK => $this->paystackGateway->verifyPayment($this->credentialsFor(PaymentGatewayCatalog::PAYSTACK), $providerReference),
            PaymentGatewayCatalog::SQUAD => $this->squad->verifyPayment($providerReference),
            default => $this->flutterwave->verifyPayment($payment, $providerReference, $resourceType),
        };

        if (! $this->verificationMatchesPayment($verification, $payment)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            Log::warning('Platform billing gateway verification did not match payment', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'provider' => $payment->provider,
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
                'provider_reference' => (string) $this->providerReferenceFromVerification($verification, $payment),
                'payload' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                ],
            ]);

            $payment->update([
                'tenant_billing_subscription_id' => $subscription->id,
                'status' => 'successful',
                'provider_reference' => (string) $this->providerReferenceFromVerification($verification, $payment),
                'paid_at' => now(),
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);
        });
    }

    public function verificationMatchesPayment(array $verification, PlatformBillingPayment $payment): bool
    {
        if ($payment->provider === PaymentGatewayCatalog::STRIPE) {
            return data_get($verification, 'object') === 'checkout.session'
                && $this->statusIsSuccessful(data_get($verification, 'payment_status'))
                && (data_get($verification, 'client_reference_id') === $payment->tx_ref
                    || data_get($verification, 'metadata.payment_reference') === $payment->tx_ref)
                && strtoupper((string) data_get($verification, 'currency')) === strtoupper($payment->currency)
                && ((float) data_get($verification, 'amount_total') / 100) >= (float) $payment->amount;
        }

        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return MonnifyVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount);
        }

        if ($payment->provider === PaymentGatewayCatalog::PAYSTACK) {
            return PaystackVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount);
        }

        if ($payment->provider === PaymentGatewayCatalog::SQUAD) {
            // Squad's own field names throughout -- top-level "success" boolean,
            // data.transaction_status/transaction_ref/transaction_amount (kobo) --
            // mirroring HotspotPaymentConfirmationService::squadVerificationMatchesPayment().
            // Squad's currency field is genuinely optional in some responses, so a
            // blank value is treated as a non-mismatch rather than a hard failure,
            // matching the tenant-side behavior exactly.
            $currency = data_get($verification, 'data.currency') ?: data_get($verification, 'data.currency_id');

            return data_get($verification, 'success') === true
                && $this->statusIsSuccessful(data_get($verification, 'data.transaction_status'))
                && data_get($verification, 'data.transaction_ref') === $payment->tx_ref
                && (blank($currency) || strtoupper((string) $currency) === strtoupper($payment->currency))
                && ((float) data_get($verification, 'data.transaction_amount') / 100) >= (float) $payment->amount;
        }

        return in_array(strtolower((string) data_get($verification, 'status')), ['success', 'successful', 'succeeded'], true)
            && $this->statusIsSuccessful(data_get($verification, 'data.status'))
            && (data_get($verification, 'data.reference') === $payment->tx_ref || data_get($verification, 'data.tx_ref') === $payment->tx_ref)
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($payment->currency)
            && (float) data_get($verification, 'data.amount') >= (float) $payment->amount;
    }

    private function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed', 'paid'], true);
    }

    private function credentialsFor(string $gateway): GatewayCredentials
    {
        return new GatewayCredentials($this->settings->gatewaySettings($gateway));
    }

    private function providerReferenceFromVerification(array $verification, PlatformBillingPayment $payment): string
    {
        return (string) (data_get($verification, 'data.id')
            ?: data_get($verification, 'id')
            ?: data_get($verification, 'data.transaction_ref')
            ?: data_get($verification, 'responseBody.transactionReference')
            ?: $payment->provider_reference);
    }
}
