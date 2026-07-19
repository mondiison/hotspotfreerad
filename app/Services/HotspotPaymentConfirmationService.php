<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HotspotPaymentConfirmationService
{
    private const ACCESS_PASSWORD = 'authenticated_device_pass';

    public function __construct(
        private readonly FlutterwaveService $flutterwave,
        private readonly RadiusProvisioningService $radius,
    ) {}

    public function verifyAndGrant(Payment $payment, string $providerReference, string $resourceType = 'order'): ?Subscription
    {
        $payment->loadMissing(['shop.tenant', 'package', 'subscription']);

        if ($payment->status === 'successful' && $payment->subscription) {
            return $payment->subscription;
        }

        $verification = $this->flutterwave->verifyPayment($payment, $providerReference, $resourceType);

        if (! $this->verificationMatchesPayment($verification, $payment)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            Log::warning('Flutterwave verification did not match hotspot payment', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
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
                'provider_reference' => (string) (data_get($verification, 'data.id') ?: $payment->provider_reference),
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
