<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PppoeSubscriber;
use App\Models\Subscription;
use App\Models\Voucher;
use App\Services\Payments\GatewayCredentialResolver;
use App\Services\Payments\Gateways\FlutterwaveGateway;
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

class HotspotPaymentConfirmationService
{
    private const ACCESS_PASSWORD = 'authenticated_device_pass';

    public function __construct(
        private readonly FlutterwaveService $flutterwave,
        private readonly RadiusProvisioningService $radius,
        private readonly WalletService $wallet,
        private readonly MonnifyGateway $monnifyGateway,
        private readonly PaystackGateway $paystackGateway,
        private readonly SquadGateway $squadGateway,
        private readonly FlutterwaveGateway $flutterwaveGateway,
        private readonly StripeGateway $stripeGateway,
        private readonly GatewayCredentialResolver $credentials,
        private readonly VoucherManagementService $vouchers,
        private readonly PppoeSubscriberManagementService $pppoeSubscribers,
    ) {}

    /**
     * Return type widened to Subscription|Voucher|PppoeSubscriber|null --
     * a payment flagged as_voucher (2026-11-09) never gets a Subscription
     * at all, it gets a Voucher instead, and a payment carrying a
     * pppoe_subscriber_id (2026-11-10, the "pay to renew" email link) gets
     * that PppoeSubscriber renewed instead of either. Every caller that
     * previously only null-checked the result (the webhook job, the retry
     * command, the admin "Verify"/manual-confirm actions) is unaffected by
     * either widening; only the customer-facing controller methods that
     * render a specific confirmation view need to branch on which type
     * came back.
     */
    public function verifyAndGrant(Payment $payment, string $providerReference, string $resourceType = 'order'): Subscription|Voucher|PppoeSubscriber|null
    {
        $payment->loadMissing(['shop.tenant', 'package', 'subscription', 'voucher', 'pppoeSubscriber']);

        if ($payment->status === 'successful' && $payment->subscription) {
            return $payment->subscription;
        }

        if ($payment->status === 'successful' && $payment->voucher) {
            return $payment->voucher;
        }

        if ($payment->status === 'successful' && $payment->pppoeSubscriber) {
            return $payment->pppoeSubscriber;
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

    /**
     * Branches on the payment's own as_voucher flag (set at checkout by
     * PortalController::pay() -- 2026-11-09, "buy a voucher for another
     * device or another person for later use") -- a voucher purchase skips
     * creating a Subscription/granting RADIUS access to the paying device
     * entirely, and instead creates a one-time voucher linked back to this
     * exact payment via VoucherManagementService::createVoucherForPayment(),
     * the same reusable core generateRecoveryVoucher() already uses. The
     * wallet credit still applies either way -- it's a real sale regardless
     * of which the customer actually walks away with.
     */
    public function markPaidAndGrantAccess(Payment $payment, array $verification): Subscription|Voucher|PppoeSubscriber
    {
        return DB::transaction(function () use ($payment, $verification): Subscription|Voucher|PppoeSubscriber {
            $payment->refresh();
            $payment->loadMissing(['package', 'subscription', 'voucher', 'pppoeSubscriber']);

            if ($payment->status === 'successful' && $payment->subscription) {
                return $payment->subscription;
            }

            if ($payment->status === 'successful' && $payment->voucher) {
                return $payment->voucher;
            }

            if ($payment->status === 'successful' && $payment->pppoeSubscriber) {
                return $payment->pppoeSubscriber;
            }

            $asVoucher = (bool) data_get($payment->payload, 'as_voucher');

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

            if ($asVoucher) {
                $voucher = $this->vouchers->createVoucherForPayment(
                    $payment,
                    'Voucher purchase -- '.$payment->tx_ref,
                    'BUY',
                    'Purchased at checkout -- for later redemption, or for another device/person.'
                );

                if ($payment->shop->tenant?->wallet_enabled) {
                    $this->wallet->creditForPayment($payment->fresh(['shop.tenant', 'package']));
                }

                return $voucher;
            }

            // 2026-11-10, "pay to renew" email link -- the Payment carries
            // pppoe_subscriber_id from the moment PppoeRenewalController::pay()
            // created it, so no new flag is needed to detect this case.
            // renewFromOnlinePayment() does the exact same expiry-extension +
            // RADIUS re-provisioning renew() does for an admin-triggered
            // manual renewal, just without creating a second, duplicate
            // Payment row -- this Payment already IS that transaction.
            if ($payment->pppoe_subscriber_id) {
                $subscriber = $this->pppoeSubscribers->renewFromOnlinePayment(
                    PppoeSubscriber::with(['shop.tenant', 'package'])->findOrFail($payment->pppoe_subscriber_id)
                );

                if ($payment->shop->tenant?->wallet_enabled) {
                    $this->wallet->creditForPayment($payment->fresh(['shop.tenant', 'package']));
                }

                return $subscriber;
            }

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

            if ($payment->shop->tenant?->wallet_enabled) {
                $this->wallet->creditForPayment($payment->fresh(['shop.tenant', 'package']));
            }

            return $subscription;
        });
    }

    public function verificationMatchesPayment(array $verification, Payment $payment): bool
    {
        if ($payment->provider === PaymentGatewayCatalog::PAYSTACK) {
            return PaystackVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount);
        }

        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return MonnifyVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount);
        }

        if ($payment->provider === PaymentGatewayCatalog::SQUAD) {
            return SquadVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount);
        }

        if ($payment->provider === PaymentGatewayCatalog::STRIPE) {
            return StripeVerificationMatcher::matches($verification, $payment->tx_ref, $payment->currency, (float) $payment->amount);
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
            return $this->paystackGateway->verifyPayment(
                $this->credentials->forPayment($payment, PaymentGatewayCatalog::PAYSTACK),
                $providerReference
            );
        }

        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return $this->monnifyGateway->verifyPayment(
                $this->credentials->forPayment($payment, PaymentGatewayCatalog::MONNIFY),
                $providerReference
            );
        }

        if ($payment->provider === PaymentGatewayCatalog::SQUAD) {
            return $this->squadGateway->verifyPayment(
                $this->credentials->forPayment($payment, PaymentGatewayCatalog::SQUAD),
                $providerReference
            );
        }

        if ($payment->provider === PaymentGatewayCatalog::STRIPE) {
            return $this->stripeGateway->verifyPayment(
                $this->credentials->forPayment($payment, PaymentGatewayCatalog::STRIPE),
                $providerReference
            );
        }

        // Flutterwave card checkout (the v3 standard hosted flow) stays on
        // the original service -- only OPay (v4 orchestration) migrated to
        // the shared FlutterwaveGateway (2026-09-26, "OPay only" scope).
        if (data_get($payment->payload, 'flutterwave_checkout_version') === 'standard_v3') {
            return $this->flutterwave->verifyPayment($payment, $providerReference, $resourceType);
        }

        return $this->flutterwaveGateway->verifyPayment(
            $this->credentials->forPayment($payment, PaymentGatewayCatalog::FLUTTERWAVE),
            $providerReference,
            $resourceType
        );
    }

    private function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed', 'paid'], true);
    }
}
