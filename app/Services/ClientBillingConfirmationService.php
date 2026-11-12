<?php

namespace App\Services;

use App\Models\ClientBillingPayment;
use App\Models\ClientBillingSubscription;
use App\Services\Payments\GatewayCredentialResolver;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\Gateways\SquadGateway;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\Payments\Verification\MonnifyVerificationMatcher;
use App\Services\Payments\Verification\PaystackVerificationMatcher;
use App\Services\Payments\Verification\SquadVerificationMatcher;
use App\Services\Payments\Verification\StripeVerificationMatcher;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors PlatformBillingConfirmationService exactly, one layer deeper --
 * a Client's payment to its Tenant instead of a Tenant's payment to the
 * platform. Reuses the existing Verification\*VerificationMatcher classes
 * completely unchanged, since a gateway's response shape never changes
 * based on who owns the credentials underneath it.
 */
class ClientBillingConfirmationService
{
    public function __construct(
        private readonly GatewayCredentialResolver $credentials,
        private readonly MonnifyGateway $monnifyGateway,
        private readonly PaystackGateway $paystackGateway,
        private readonly SquadGateway $squadGateway,
        private readonly StripeGateway $stripeGateway,
    ) {}

    public function verifyAndActivate(ClientBillingPayment $payment, string $providerReference): bool
    {
        $payment->loadMissing(['client.tenant', 'clientBillingPlan']);

        if ($payment->status === 'successful' && $payment->client_billing_subscription_id) {
            return true;
        }

        $credentials = $this->credentials->forTenant($payment->client->tenant, $payment->provider);

        $verification = match ($payment->provider) {
            PaymentGatewayCatalog::STRIPE => $this->stripeGateway->verifyPayment($credentials, $providerReference),
            PaymentGatewayCatalog::MONNIFY => $this->monnifyGateway->verifyPayment($credentials, $providerReference),
            PaymentGatewayCatalog::PAYSTACK => $this->paystackGateway->verifyPayment($credentials, $providerReference),
            PaymentGatewayCatalog::SQUAD => $this->squadGateway->verifyPayment($credentials, $providerReference),
            default => null,
        };

        if ($verification === null || ! $this->verificationMatchesPayment($verification, $payment)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            Log::warning('Client billing gateway verification did not match payment', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'provider' => $payment->provider,
            ]);

            return false;
        }

        $this->activateSubscription($payment, $verification);

        return true;
    }

    public function activateSubscription(ClientBillingPayment $payment, array $verification): void
    {
        DB::transaction(function () use ($payment, $verification): void {
            $payment->refresh();

            if ($payment->status === 'successful' && $payment->client_billing_subscription_id) {
                return;
            }

            $subscription = ClientBillingSubscription::create([
                'client_id' => $payment->client_id,
                'client_billing_plan_id' => $payment->client_billing_plan_id,
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
                'client_billing_subscription_id' => $subscription->id,
                'status' => 'successful',
                'provider_reference' => (string) $this->providerReferenceFromVerification($verification, $payment),
                'paid_at' => now(),
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);
        });
    }

    public function verificationMatchesPayment(array $verification, ClientBillingPayment $payment): bool
    {
        return match ($payment->provider) {
            PaymentGatewayCatalog::STRIPE => StripeVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount),
            PaymentGatewayCatalog::MONNIFY => MonnifyVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount),
            PaymentGatewayCatalog::PAYSTACK => PaystackVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount),
            PaymentGatewayCatalog::SQUAD => SquadVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount),
            default => false,
        };
    }

    private function providerReferenceFromVerification(array $verification, ClientBillingPayment $payment): string
    {
        return (string) (data_get($verification, 'data.id')
            ?: data_get($verification, 'id')
            ?: data_get($verification, 'data.transaction_ref')
            ?: data_get($verification, 'responseBody.transactionReference')
            ?: $payment->provider_reference);
    }
}
