<?php

namespace App\Services\Payments;

use App\Models\PlatformBillingPayment;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\PlatformFlutterwaveService;
use App\Services\PlatformPaymentSettingsService;
use App\Services\PlatformPaystackService;
use App\Services\PlatformSquadService;
use App\Services\PlatformStripeService;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Platform-side mirror of HotspotHostedCheckoutManager -- previously
 * BillingController::checkout()/webhook() inlined this same per-gateway
 * dispatch (which service, which redirect-URL quirk, which webhook header)
 * directly in the controller, the one asymmetry between the two checkout
 * flows this app has.
 *
 * Monnify is the one gateway migrated to the shared MonnifyGateway so far
 * (2026-09-26) -- Flutterwave/Stripe/Paystack/Squad still go through their
 * own Platform*Service classes until they're migrated the same way in a
 * follow-up pass. PlatformMonnifyService itself is left in place,
 * unrefactored -- it still backs BankAccountResolutionService's
 * banks()/resolveAccount() calls, unrelated to checkout.
 */
class PlatformHostedCheckoutManager
{
    public function __construct(
        private readonly PlatformFlutterwaveService $flutterwave,
        private readonly PlatformStripeService $stripe,
        private readonly PlatformPaystackService $paystack,
        private readonly PlatformSquadService $squad,
        private readonly PlatformPaymentSettingsService $settings,
        private readonly MonnifyGateway $monnifyGateway,
    ) {}

    /**
     * Card can't go through Flutterwave's v4 orchestration initializeCheckout()
     * at all -- that API's payment_method.type: "card" needs real encrypted
     * card details this server-side redirect flow never collects, the same
     * reason the hotspot-side PortalController::pay() routes "card" around its
     * own initializeCheckout() call entirely. It uses the older v3 hosted
     * checkout instead, authenticated with a separate secret key -- public so
     * BillingController can pick the right "not configured" error message.
     */
    public function isFlutterwaveCard(string $gateway): bool
    {
        return $gateway === PaymentGatewayCatalog::FLUTTERWAVE
            && $this->settings->defaultPaymentMethod() === 'card';
    }

    public function isConfigured(string $gateway): bool
    {
        if ($this->isFlutterwaveCard($gateway)) {
            return $this->flutterwave->hasHostedCheckout();
        }

        if ($gateway === PaymentGatewayCatalog::MONNIFY) {
            return $this->monnifyGateway->isConfigured($this->monnifyCredentials());
        }

        return $this->gatewayFor($gateway)->isConfigured();
    }

    /**
     * @return array{checkout_url: ?string, unavailable_reason: ?string}
     */
    public function start(PlatformBillingPayment $payment): array
    {
        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return $this->startMonnify($payment);
        }

        $gateway = $payment->provider;
        $isFlutterwaveCard = $this->isFlutterwaveCard($gateway);

        try {
            $redirectUrl = $this->redirectUrl($gateway, $payment);

            $checkout = $isFlutterwaveCard
                ? $this->flutterwave->createStandardHostedCheckout($payment->load(['tenant', 'billingPlan']), $redirectUrl)
                : $this->gatewayFor($gateway)->initializeCheckout($payment->load(['tenant', 'billingPlan']), $redirectUrl);

            $payment->update([
                'provider_reference' => $checkout['provider_reference'],
                'payload' => array_merge($payment->payload ?? [], [
                    'checkout_url' => $checkout['checkout_url'],
                    'gateway_init_response' => $checkout['response'],
                    $payment->provider.'_init_response' => $checkout['response'],
                    ...($isFlutterwaveCard ? ['flutterwave_checkout_version' => 'standard_v3'] : []),
                ]),
            ]);

            if (filled($checkout['checkout_url'])) {
                return ['checkout_url' => $checkout['checkout_url'], 'unavailable_reason' => null];
            }

            Log::warning('Platform billing checkout response missing checkout URL', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'provider' => $gateway,
                'response_body' => $checkout['response'] ?? null,
            ]);

            return ['checkout_url' => null, 'unavailable_reason' => 'missing_checkout_url'];
        } catch (Throwable $exception) {
            Log::warning('Platform billing checkout initialization failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
                'response_body' => $exception instanceof RequestException
                    ? $exception->response->json() ?: $exception->response->body()
                    : null,
            ]);

            return ['checkout_url' => null, 'unavailable_reason' => 'initialization_failed'];
        }
    }

    public function webhookIsValid(Request $request, string $gateway): bool
    {
        if ($gateway === PaymentGatewayCatalog::STRIPE) {
            return $this->stripe->webhookIsValid($request->getContent(), $request->header('stripe-signature'));
        }

        if ($gateway === PaymentGatewayCatalog::MONNIFY) {
            return $this->monnifyGateway->webhookIsValid($this->monnifyCredentials(), $request->getContent(), $request->header('monnify-signature'));
        }

        if ($gateway === PaymentGatewayCatalog::PAYSTACK) {
            return $this->paystack->webhookIsValid($request->getContent(), $request->header('x-paystack-signature'));
        }

        if ($gateway === PaymentGatewayCatalog::SQUAD) {
            return $this->squad->webhookIsValid($request->getContent(), $request->header('x-squad-encrypted-body'));
        }

        return $this->flutterwave->webhookIsValid($request->getContent(), $request->header('flutterwave-signature') ?: $request->header('verif-hash'));
    }

    /**
     * @return array{checkout_url: ?string, unavailable_reason: ?string}
     */
    private function startMonnify(PlatformBillingPayment $payment): array
    {
        $credentials = $this->monnifyCredentials();

        try {
            $payment->load(['tenant', 'billingPlan']);

            $chargeRequest = new ChargeRequest(
                reference: $payment->tx_ref,
                amount: (float) $payment->amount,
                currency: $payment->currency,
                redirectUrl: $this->redirectUrl($payment->provider, $payment),
                customerEmail: $payment->tenant->owner_email,
                customerName: $payment->tenant->company_name,
                description: $payment->billingPlan->name.' platform subscription',
                meta: [
                    'payment_type' => 'platform_subscription',
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                    'tenant_id' => $payment->tenant_id,
                    'tenant_name' => $payment->tenant->company_name,
                    'billing_plan_id' => $payment->billing_plan_id,
                    'billing_plan_name' => $payment->billingPlan->name,
                ],
            );

            $result = $this->monnifyGateway->initializeCheckout($credentials, $chargeRequest);

            $payment->update([
                'provider_reference' => $result->providerReference,
                'payload' => array_merge($payment->payload ?? [], [
                    'checkout_url' => $result->checkoutUrl,
                    'gateway_init_response' => $result->response,
                    $payment->provider.'_init_response' => $result->response,
                ]),
            ]);

            if (filled($result->checkoutUrl)) {
                return ['checkout_url' => $result->checkoutUrl, 'unavailable_reason' => null];
            }

            Log::warning('Platform billing checkout response missing checkout URL', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'provider' => $payment->provider,
                'response_body' => $result->response,
            ]);

            return ['checkout_url' => null, 'unavailable_reason' => 'missing_checkout_url'];
        } catch (Throwable $exception) {
            Log::warning('Platform billing checkout initialization failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
                'response_body' => $exception instanceof RequestException
                    ? $exception->response->json() ?: $exception->response->body()
                    : null,
            ]);

            return ['checkout_url' => null, 'unavailable_reason' => 'initialization_failed'];
        }
    }

    private function monnifyCredentials(): GatewayCredentials
    {
        return new GatewayCredentials($this->settings->gatewaySettings(PaymentGatewayCatalog::MONNIFY));
    }

    private function gatewayFor(string $gateway): PlatformFlutterwaveService|PlatformStripeService|PlatformPaystackService|PlatformSquadService
    {
        return match ($gateway) {
            PaymentGatewayCatalog::STRIPE => $this->stripe,
            PaymentGatewayCatalog::PAYSTACK => $this->paystack,
            PaymentGatewayCatalog::SQUAD => $this->squad,
            default => $this->flutterwave,
        };
    }

    /**
     * Same reasoning as HotspotHostedCheckoutManager::callbackUrl(): Monnify
     * (and, per that same method's own "default" grouping, Paystack too)
     * appends its own reference query params to whatever redirectUrl it's
     * given, using "?" rather than checking for an existing query string --
     * pre-embedding our own ?tx_ref=... here would produce the same doubled,
     * malformed query string confirmed live on the hotspot side for Monnify.
     * Squad is different again -- it never appends anything of its own, so
     * (mirroring callbackUrl()'s own Squad case) it needs an explicit
     * transaction_ref embedded, just under its own param name rather than
     * the generic tx_ref.
     */
    private function redirectUrl(string $gateway, PlatformBillingPayment $payment): string
    {
        return match (true) {
            in_array($gateway, [PaymentGatewayCatalog::MONNIFY, PaymentGatewayCatalog::PAYSTACK], true) => route('admin.billing.payments.callback'),
            $gateway === PaymentGatewayCatalog::SQUAD => route('admin.billing.payments.callback', ['transaction_ref' => $payment->tx_ref]),
            default => route('admin.billing.payments.callback', ['tx_ref' => $payment->tx_ref]),
        };
    }
}
