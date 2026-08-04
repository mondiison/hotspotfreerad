<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscription;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HotspotPaymentConfirmationService
{
    private const ACCESS_PASSWORD = 'authenticated_device_pass';

    public function __construct(
        private readonly FlutterwaveService $flutterwave,
        private readonly MonnifyService $monnify,
        private readonly PaystackService $paystack,
        private readonly RadiusProvisioningService $radius,
        private readonly SquadService $squad,
        private readonly StripeService $stripe,
    ) {}

    public function verifyAndGrant(Payment $payment, string $providerReference, string $resourceType = 'order'): ?Subscription
    {
        $payment->loadMissing(['shop.tenant', 'package', 'subscription']);

        if ($payment->status === 'successful' && $payment->subscription) {
            return $payment->subscription;
        }

        $verification = $this->verifyProviderPayment($payment, $providerReference, $resourceType);

        if (! $this->verificationMatchesPayment($verification, $payment)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            Log::warning('Payment gateway verification did not match hotspot payment', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'provider' => $payment->provider,
            ]);

            return null;
        }

        return $this->markPaidAndGrantAccess($payment, $verification);
    }

    public function markPaidAndGrantAccess(Payment $payment, array $verification): Subscription
    {
        return DB::transaction(function () use ($payment, $verification): Subscription {
            $payment->refresh();
            $payment->loadMissing(['package', 'subscription']);

            if ($payment->status === 'successful' && $payment->subscription) {
                return $payment->subscription;
            }

            $payment->update([
                'status' => 'successful',
                'provider_reference' => (string) (data_get($verification, 'data.id')
                    ?: data_get($verification, 'data.reference')
                    ?: data_get($verification, 'data.transaction_ref')
                    ?: data_get($verification, 'responseBody.transactionReference')
                    ?: $payment->provider_reference),
                'paid_at' => now(),
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            $subscription = Subscription::updateOrCreate(
                [
                    'shop_id' => $payment->shop_id,
                    'mac_address' => $payment->payload['mac'],
                ],
                [
                    'package_id' => $payment->package_id,
                    'payment_id' => $payment->id,
                    'starts_at' => now(),
                    'expires_at' => now()->addSeconds($payment->package->limit_uptime_seconds),
                    'is_throttled' => false,
                ]
            );

            $this->radius->grantSubscriptionAccess($subscription, self::ACCESS_PASSWORD);

            return $subscription;
        });
    }

    public function verificationMatchesPayment(array $verification, Payment $payment): bool
    {
        if ($payment->provider === PaymentGatewayCatalog::PAYSTACK) {
            return $this->paystackVerificationMatchesPayment($verification, $payment);
        }

        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return $this->monnifyVerificationMatchesPayment($verification, $payment);
        }

        if ($payment->provider === PaymentGatewayCatalog::SQUAD) {
            return $this->squadVerificationMatchesPayment($verification, $payment);
        }

        if ($payment->provider === PaymentGatewayCatalog::STRIPE) {
            return $this->stripeVerificationMatchesPayment($verification, $payment);
        }

        return in_array(strtolower((string) data_get($verification, 'status')), ['success', 'successful', 'succeeded'], true)
            && $this->statusIsSuccessful(data_get($verification, 'data.status'))
            && (data_get($verification, 'data.reference') === $payment->tx_ref || data_get($verification, 'data.tx_ref') === $payment->tx_ref)
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($payment->currency)
            && (float) data_get($verification, 'data.amount') >= (float) $payment->amount;
    }

    private function verifyProviderPayment(Payment $payment, string $providerReference, string $resourceType): array
    {
        if ($payment->provider === PaymentGatewayCatalog::PAYSTACK) {
            return $this->paystack->verifyPayment($payment, $providerReference);
        }

        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return $this->monnify->verifyPayment($payment, $providerReference);
        }

        if ($payment->provider === PaymentGatewayCatalog::SQUAD) {
            return $this->squad->verifyPayment($payment, $providerReference);
        }

        if ($payment->provider === PaymentGatewayCatalog::STRIPE) {
            return $this->stripe->verifyPayment($payment, $providerReference);
        }

        return $this->flutterwave->verifyPayment($payment, $providerReference, $resourceType);
    }

    private function paystackVerificationMatchesPayment(array $verification, Payment $payment): bool
    {
        return data_get($verification, 'status') === true
            && $this->statusIsSuccessful(data_get($verification, 'data.status'))
            && data_get($verification, 'data.reference') === $payment->tx_ref
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($payment->currency)
            && ((float) data_get($verification, 'data.amount') / 100) >= (float) $payment->amount;
    }

    private function monnifyVerificationMatchesPayment(array $verification, Payment $payment): bool
    {
        return data_get($verification, 'requestSuccessful') === true
            && $this->statusIsSuccessful(data_get($verification, 'responseBody.paymentStatus'))
            && data_get($verification, 'responseBody.paymentReference') === $payment->tx_ref
            && strtoupper((string) data_get($verification, 'responseBody.currency')) === strtoupper($payment->currency)
            && (float) data_get($verification, 'responseBody.amountPaid') >= (float) $payment->amount;
    }

    private function squadVerificationMatchesPayment(array $verification, Payment $payment): bool
    {
        $currency = data_get($verification, 'data.currency') ?: data_get($verification, 'data.currency_id');

        return data_get($verification, 'success') === true
            && $this->statusIsSuccessful(data_get($verification, 'data.transaction_status'))
            && data_get($verification, 'data.transaction_ref') === $payment->tx_ref
            && (blank($currency) || strtoupper((string) $currency) === strtoupper($payment->currency))
            && ((float) data_get($verification, 'data.transaction_amount') / 100) >= (float) $payment->amount;
    }

    private function stripeVerificationMatchesPayment(array $verification, Payment $payment): bool
    {
        return data_get($verification, 'object') === 'checkout.session'
            && $this->statusIsSuccessful(data_get($verification, 'payment_status'))
            && (data_get($verification, 'client_reference_id') === $payment->tx_ref
                || data_get($verification, 'metadata.payment_reference') === $payment->tx_ref)
            && strtoupper((string) data_get($verification, 'currency')) === strtoupper($payment->currency)
            && ((float) data_get($verification, 'amount_total') / 100) >= (float) $payment->amount;
    }

    private function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed', 'paid'], true);
    }
}
