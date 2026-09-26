<?php

namespace App\Services\Payments;

use App\Models\PlatformBillingPayment;
use App\Services\Payments\Contracts\HostedGateway;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\Gateways\SquadGateway;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\PlatformFlutterwaveService;
use App\Services\PlatformPaymentSettingsService;
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
 * Monnify, Paystack, Squad, and (2026-09-26) Stripe are all migrated to the
 * shared HostedGateway contract now -- Flutterwave alone still goes through
 * its own PlatformFlutterwaveService until a future pass, since it doesn't
 * fit the tenant-side hotspot checkout's "OPay only" FlutterwaveGateway
 * pilot at all: unlike the hotspot portal (where OPay is one of three fixed,
 * user-selected checkout methods), platform billing's own non-card
 * Flutterwave flow sends whatever v4 payment_method.type
 * PlatformPaymentSettingsService::defaultPaymentMethod() is currently
 * configured to (opay OR bank_transfer, both through the same orchestration
 * endpoint) -- a dynamic choice the OPay-only gateway class deliberately
 * hardcodes away, confirmed live by
 * test_platform_checkout_uses_database_payment_settings_before_env failing
 * outright the moment this path was pointed at FlutterwaveGateway during
 * that migration. PlatformMonnifyService was left in place (still backing
 * BankAccountResolutionService's banks()/resolveAccount() calls, unrelated
 * to checkout), but PlatformPaystackService/PlatformSquadService/PlatformStripeService
 * were all deleted entirely, since nothing else depended on any of them.
 */
class PlatformHostedCheckoutManager
{
    public function __construct(
        private readonly PlatformFlutterwaveService $flutterwave,
        private readonly PlatformPaymentSettingsService $settings,
        private readonly MonnifyGateway $monnifyGateway,
        private readonly PaystackGateway $paystackGateway,
        private readonly SquadGateway $squadGateway,
        private readonly StripeGateway $stripeGateway,
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
            return $this->monnifyGateway->isConfigured($this->credentialsFor(PaymentGatewayCatalog::MONNIFY));
        }

        if ($gateway === PaymentGatewayCatalog::PAYSTACK) {
            return $this->paystackGateway->isConfigured($this->credentialsFor(PaymentGatewayCatalog::PAYSTACK));
        }

        if ($gateway === PaymentGatewayCatalog::SQUAD) {
            return $this->squadGateway->isConfigured($this->credentialsFor(PaymentGatewayCatalog::SQUAD));
        }

        if ($gateway === PaymentGatewayCatalog::STRIPE) {
            return $this->stripeGateway->isConfigured($this->credentialsFor(PaymentGatewayCatalog::STRIPE));
        }

        return $this->flutterwave->isConfigured();
    }

    /**
     * @return array{checkout_url: ?string, unavailable_reason: ?string}
     */
    public function start(PlatformBillingPayment $payment): array
    {
        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return $this->startSharedGateway($payment, $this->monnifyGateway, PaymentGatewayCatalog::MONNIFY);
        }

        if ($payment->provider === PaymentGatewayCatalog::PAYSTACK) {
            return $this->startSharedGateway($payment, $this->paystackGateway, PaymentGatewayCatalog::PAYSTACK);
        }

        if ($payment->provider === PaymentGatewayCatalog::SQUAD) {
            return $this->startSharedGateway($payment, $this->squadGateway, PaymentGatewayCatalog::SQUAD);
        }

        if ($payment->provider === PaymentGatewayCatalog::STRIPE) {
            return $this->startSharedGateway($payment, $this->stripeGateway, PaymentGatewayCatalog::STRIPE);
        }

        // Only Flutterwave reaches here now -- card via the v3 hosted
        // checkout, everything else via v4 orchestration with whatever
        // payment_method.type is currently configured (see this class's own
        // docblock for why that flow doesn't fit the shared gateway).
        $gateway = $payment->provider;
        $isFlutterwaveCard = $this->isFlutterwaveCard($gateway);

        try {
            $redirectUrl = $this->redirectUrl($gateway, $payment);

            $checkout = $isFlutterwaveCard
                ? $this->flutterwave->createStandardHostedCheckout($payment->load(['tenant', 'billingPlan']), $redirectUrl)
                : $this->flutterwave->initializeCheckout($payment->load(['tenant', 'billingPlan']), $redirectUrl);

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
            return $this->stripeGateway->webhookIsValid($this->credentialsFor(PaymentGatewayCatalog::STRIPE), $request->getContent(), $request->header('stripe-signature'));
        }

        if ($gateway === PaymentGatewayCatalog::MONNIFY) {
            return $this->monnifyGateway->webhookIsValid($this->credentialsFor(PaymentGatewayCatalog::MONNIFY), $request->getContent(), $request->header('monnify-signature'));
        }

        if ($gateway === PaymentGatewayCatalog::PAYSTACK) {
            return $this->paystackGateway->webhookIsValid($this->credentialsFor(PaymentGatewayCatalog::PAYSTACK), $request->getContent(), $request->header('x-paystack-signature'));
        }

        if ($gateway === PaymentGatewayCatalog::SQUAD) {
            return $this->squadGateway->webhookIsValid($this->credentialsFor(PaymentGatewayCatalog::SQUAD), $request->getContent(), $request->header('x-squad-encrypted-body'));
        }

        return $this->flutterwave->webhookIsValid($request->getContent(), $request->header('flutterwave-signature') ?: $request->header('verif-hash'));
    }

    /**
     * @return array{checkout_url: ?string, unavailable_reason: ?string}
     */
    private function startSharedGateway(PlatformBillingPayment $payment, HostedGateway $gateway, string $gatewayKey): array
    {
        $credentials = $this->credentialsFor($gatewayKey);

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
                customerPhone: (string) ($payment->tenant->contact_phone ?? ''),
                addressCity: 'Lagos',
                addressState: 'Lagos',
                addressLine1: $payment->tenant->company_name,
                cancelUrl: route('admin.billing.index'),
                productName: $payment->billingPlan->name,
            );

            $result = $gateway->initializeCheckout($credentials, $chargeRequest);

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

    private function credentialsFor(string $gateway): GatewayCredentials
    {
        return new GatewayCredentials($this->settings->gatewaySettings($gateway));
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
