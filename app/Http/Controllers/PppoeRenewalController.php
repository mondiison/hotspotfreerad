<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PppoeSubscriber;
use App\Services\FlutterwaveService;
use App\Services\ManualBankTransferService;
use App\Services\Payments\HotspotHostedCheckoutManager;
use App\Support\PaymentCommission;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * 2026-11-10, direct request ("can we integrate payment link from the
 * email... automated when they pay they get renewed automatically") -- a
 * standalone, out-of-band checkout surface for PPPoE subscribers reached
 * via the expiry reminder emails, not through MikroTik's captive portal at
 * all (PPPoE has no router-redirect/walled-garden concept the way the
 * hotspot flow does). Identified by PppoeSubscriber::public_token (the
 * same short-random-token-in-a-public-URL precedent Router::public_token
 * already set for the self-service connect link), not a signed URL -- no
 * signed-URL mechanism exists anywhere else in this codebase, and the
 * worst case of an unsigned token (a stranger paying to extend someone
 * else's subscription) costs the "attacker" real money to benefit a
 * stranger, not a risk worth a new mechanism to guard against.
 *
 * Deliberately reuses the EXACT same confirmation plumbing hotspot
 * checkout already uses (HotspotPaymentConfirmationService::verifyAndGrant(),
 * the /hotspot/payment/callback|verify|webhook routes, hotspot:retry-pending-
 * payments) rather than building a second, parallel gateway-verification
 * surface -- those are all already payment-row-generic (dispatch purely on
 * Payment.provider / Payment.pppoe_subscriber_id), so only the *initiation*
 * step below (there's no PPPoE equivalent of PortalController::pay()) and
 * PortalController::confirmationView()'s PppoeSubscriber branch needed to
 * be written. Scoped to the shop's active gateway plus Flutterwave OPay
 * plus manual bank transfer -- deliberately omits Flutterwave's card/
 * bank-transfer-virtual-account sub-flows and the payment-method picker
 * hotspot checkout offers, since an email link's "just pay" UX doesn't
 * need that choice surfaced.
 */
class PppoeRenewalController extends Controller
{
    public function show(string $token): View
    {
        $subscriber = PppoeSubscriber::with(['shop.tenant', 'package'])
            ->where('public_token', $token)
            ->firstOrFail();

        return view('pppoe.renew', ['subscriber' => $subscriber]);
    }

    public function pay(string $token, FlutterwaveService $flutterwave, HotspotHostedCheckoutManager $hostedGateways, ManualBankTransferService $manualBank): RedirectResponse|View
    {
        $subscriber = PppoeSubscriber::with(['shop.tenant', 'package'])
            ->where('public_token', $token)
            ->firstOrFail();

        $shop = $subscriber->shop;
        $package = $subscriber->package;
        $commission = PaymentCommission::forShop($shop, (float) $package->price);

        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'pppoe_subscriber_id' => $subscriber->id,
            'provider' => $shop->paymentGateway(),
            'tx_ref' => 'PPPOER-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
            'amount' => $commission['gross_amount'],
            'gross_amount' => $commission['gross_amount'],
            'platform_fee_amount' => $commission['platform_fee_amount'],
            'tenant_net_amount' => $commission['tenant_net_amount'],
            'commission_rate' => $commission['commission_rate'],
            'billing_model' => $commission['billing_model'],
            'currency' => $package->currency,
            'status' => 'pending',
            'payload' => [
                'username' => $subscriber->username,
                'payment_gateway' => $shop->paymentGateway(),
                'payment_gateway_name' => $shop->paymentGatewayName(),
            ],
        ]);

        $payment->load(['shop.tenant', 'package']);
        $credentialSource = $flutterwave->credentialSource($payment);
        $checkoutUnavailableReason = 'missing_credentials';

        if (! in_array($payment->provider, PaymentGatewayCatalog::implementedGatewayKeys(), true)) {
            $checkoutUnavailableReason = 'gateway_not_live';
        } elseif ($payment->provider === PaymentGatewayCatalog::MANUAL_BANK) {
            if (! $manualBank->isConfiguredFor($payment)) {
                $checkoutUnavailableReason = 'missing_manual_bank_details';
            } else {
                $payment->update([
                    'provider_reference' => $payment->tx_ref,
                    'payload' => array_merge($payment->payload ?? [], [
                        'payment_channel' => 'Manual bank transfer',
                        'manual_bank_transfer' => $manualBank->transferDetails($payment),
                    ]),
                ]);

                return view('pppoe.manual-bank-transfer', [
                    'subscriber' => $subscriber,
                    'payment' => $payment->fresh(['shop.tenant', 'package']),
                    'transfer' => $manualBank->transferDetails($payment),
                ]);
            }
        } elseif ($hostedGateways->supports($payment)) {
            $attempt = $hostedGateways->start($payment, [
                'email' => $subscriber->email,
                'phone' => $subscriber->phone,
                'name' => $subscriber->full_name ?: $subscriber->username,
            ]);
            $credentialSource = $attempt['credential_source'];
            $checkoutUnavailableReason = $attempt['unavailable_reason'] ?? $checkoutUnavailableReason;

            if (filled($attempt['checkout_url'] ?? null)) {
                return redirect()->away($attempt['checkout_url']);
            }
        } elseif ($payment->provider === PaymentGatewayCatalog::FLUTTERWAVE && $flutterwave->isConfiguredFor($payment)) {
            $attempt = $hostedGateways->startFlutterwaveOpay($payment, [
                'email' => $subscriber->email,
                'phone' => $subscriber->phone,
                'name' => $subscriber->full_name ?: $subscriber->username,
            ], $credentialSource);
            $credentialSource = $attempt['credential_source'];
            $checkoutUnavailableReason = $attempt['unavailable_reason'] ?? $checkoutUnavailableReason;

            if (filled($attempt['checkout_url'] ?? null)) {
                return redirect()->away($attempt['checkout_url']);
            }
        }

        return view('pppoe.checkout-unavailable', [
            'subscriber' => $subscriber,
            'payment' => $payment,
            'credentialSource' => $credentialSource,
            'checkoutUnavailableReason' => $checkoutUnavailableReason,
        ]);
    }
}
