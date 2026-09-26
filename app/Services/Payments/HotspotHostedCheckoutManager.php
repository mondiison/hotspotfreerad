<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Payments\Contracts\HostedGateway;
use App\Services\Payments\Gateways\FlutterwaveGateway;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\Gateways\SquadGateway;
use App\Services\StripeService;
use App\Support\GuestCustomerEmail;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class HotspotHostedCheckoutManager
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly MonnifyGateway $monnifyGateway,
        private readonly PaystackGateway $paystackGateway,
        private readonly SquadGateway $squadGateway,
        private readonly FlutterwaveGateway $flutterwaveGateway,
        private readonly GatewayCredentialResolver $credentials,
    ) {}

    public function supports(Payment $payment): bool
    {
        return in_array($payment->provider, [
            PaymentGatewayCatalog::MONNIFY,
            PaymentGatewayCatalog::PAYSTACK,
            PaymentGatewayCatalog::SQUAD,
            PaymentGatewayCatalog::STRIPE,
        ], true);
    }

    /**
     * @return array{credential_source: array<string, string>, checkout_url: ?string, unavailable_reason: ?string}
     */
    public function start(Payment $payment, array $customer): array
    {
        // Monnify, Paystack, and Squad are migrated to the shared HostedGateway
        // contract so far (2026-09-26) -- Stripe still goes through the older
        // HotspotHostedGateway/Payment-coupled path below until it's migrated
        // the same way in a follow-up pass.
        if ($payment->provider === PaymentGatewayCatalog::MONNIFY) {
            return $this->startSharedGateway($payment, $customer, $this->monnifyGateway, PaymentGatewayCatalog::MONNIFY, 'Monnify');
        }

        if ($payment->provider === PaymentGatewayCatalog::PAYSTACK) {
            return $this->startSharedGateway($payment, $customer, $this->paystackGateway, PaymentGatewayCatalog::PAYSTACK, 'Paystack');
        }

        if ($payment->provider === PaymentGatewayCatalog::SQUAD) {
            return $this->startSharedGateway($payment, $customer, $this->squadGateway, PaymentGatewayCatalog::SQUAD, 'Squad');
        }

        $gateway = $this->stripe;
        $credentialSource = $gateway->credentialSource($payment);

        if (! $gateway->isConfiguredFor($payment)) {
            return $this->result($credentialSource, null, 'missing_gateway_secret_key');
        }

        try {
            $checkout = $gateway->initializeCheckout(
                $payment,
                $customer,
                $this->callbackUrl($payment)
            );

            $payment->update([
                'provider_reference' => $checkout['provider_reference'],
                'payload' => array_merge($payment->payload ?? [], [
                    'checkout_url' => $checkout['checkout_url'],
                    $payment->provider.'_account' => $credentialSource,
                    $payment->provider.'_init_response' => $checkout['response'],
                ]),
            ]);

            if (filled($checkout['checkout_url'])) {
                return $this->result($credentialSource, $checkout['checkout_url'], null);
            }

            Log::warning(PaymentGatewayCatalog::gatewayName($payment->provider).' checkout response missing checkout URL', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'payment_method' => data_get($payment->payload, 'payment_method'),
                'response_body' => $checkout['response'] ?? null,
            ]);

            return $this->result($credentialSource, null, 'missing_checkout_url');
        } catch (Throwable $exception) {
            $reason = $this->checkoutFailureReason($exception);

            Log::warning(PaymentGatewayCatalog::gatewayName($payment->provider).' checkout initialization failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
                'response_body' => $exception instanceof RequestException
                    ? $exception->response->json() ?: $exception->response->body()
                    : null,
            ]);

            return $this->result($credentialSource, null, $reason);
        }
    }

    /**
     * Called directly by PortalController::webhook() for Monnify/Paystack
     * specifically -- that controller still dispatches every other gateway's
     * webhook signature check inline (a pre-existing pattern, not something
     * this migration set out to change), so these are narrow, named entry
     * points rather than a general webhookIsValid() covering all four
     * gateways this manager knows about.
     */
    public function monnifyWebhookIsValid(Payment $payment, string $rawBody, ?string $signature): bool
    {
        return $this->monnifyGateway->webhookIsValid(
            $this->credentials->forPayment($payment, PaymentGatewayCatalog::MONNIFY),
            $rawBody,
            $signature
        );
    }

    public function paystackWebhookIsValid(Payment $payment, string $rawBody, ?string $signature): bool
    {
        return $this->paystackGateway->webhookIsValid(
            $this->credentials->forPayment($payment, PaymentGatewayCatalog::PAYSTACK),
            $rawBody,
            $signature
        );
    }

    public function squadWebhookIsValid(Payment $payment, string $rawBody, ?string $signature): bool
    {
        return $this->squadGateway->webhookIsValid(
            $this->credentials->forPayment($payment, PaymentGatewayCatalog::SQUAD),
            $rawBody,
            $signature
        );
    }

    /**
     * Called directly by PortalController::pay() for Flutterwave's OPay
     * branch specifically -- Flutterwave was never routed through
     * supports()/start() at all (card and bank transfer are dispatched by
     * the controller itself, since neither fits this manager's
     * checkout-URL-shaped result), so this mirrors start()'s return
     * contract without needing supports() to know about Flutterwave.
     * $credentialSource is passed in rather than computed here because the
     * controller already needs it before this call, to choose which of
     * several checkout methods to attempt.
     *
     * @return array{credential_source: array<string, string>, checkout_url: ?string, unavailable_reason: ?string}
     */
    public function startFlutterwaveOpay(Payment $payment, array $customer, array $credentialSource): array
    {
        $credentials = $this->credentials->forPayment($payment, PaymentGatewayCatalog::FLUTTERWAVE);

        if (! $this->flutterwaveGateway->isConfigured($credentials)) {
            return $this->result($credentialSource, null, 'missing_gateway_secret_key');
        }

        $chargeRequest = $this->hotspotChargeRequest($payment, $customer, [
            'credential_source' => $credentialSource['source'],
            'credential_label' => $credentialSource['label'],
        ]);

        try {
            $result = $this->flutterwaveGateway->initializeCheckout($credentials, $chargeRequest);

            $payment->update([
                'provider_reference' => $result->providerReference,
                'payload' => array_merge($payment->payload ?? [], [
                    'checkout_url' => $result->checkoutUrl,
                    'flutterwave_account' => $credentialSource,
                    'flutterwave_init_response' => $result->response,
                ]),
            ]);

            if (filled($result->checkoutUrl)) {
                return $this->result($credentialSource, $result->checkoutUrl, null);
            }

            Log::warning('Flutterwave checkout response missing redirect URL', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'payment_method' => 'opay',
                'response_body' => $result->response,
            ]);

            return $this->result($credentialSource, null, 'missing_checkout_url');
        } catch (Throwable $exception) {
            $reason = $this->checkoutFailureReason($exception);

            Log::warning('Flutterwave checkout initialization failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
                'response_body' => $exception instanceof RequestException
                    ? $exception->response->json() ?: $exception->response->body()
                    : null,
            ]);

            return $this->result($credentialSource, null, $reason);
        }
    }

    /**
     * @return array{credential_source: array<string, string>, checkout_url: ?string, unavailable_reason: ?string}
     */
    private function startSharedGateway(Payment $payment, array $customer, HostedGateway $gateway, string $gatewayKey, string $gatewayLabel): array
    {
        $credentials = $this->credentials->forPayment($payment, $gatewayKey);
        $credentialSource = $this->credentialSourceFor($payment, $gateway, $credentials, $gatewayLabel);

        if (! $gateway->isConfigured($credentials)) {
            return $this->result($credentialSource, null, 'missing_gateway_secret_key');
        }

        try {
            $result = $gateway->initializeCheckout($credentials, $this->hotspotChargeRequest($payment, $customer));

            $payment->update([
                'provider_reference' => $result->providerReference,
                'payload' => array_merge($payment->payload ?? [], [
                    'checkout_url' => $result->checkoutUrl,
                    $payment->provider.'_account' => $credentialSource,
                    $payment->provider.'_init_response' => $result->response,
                ]),
            ]);

            if (filled($result->checkoutUrl)) {
                return $this->result($credentialSource, $result->checkoutUrl, null);
            }

            Log::warning($gatewayLabel.' checkout response missing checkout URL', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'response_body' => $result->response,
            ]);

            return $this->result($credentialSource, null, 'missing_checkout_url');
        } catch (Throwable $exception) {
            $reason = $this->checkoutFailureReason($exception);

            Log::warning($gatewayLabel.' checkout initialization failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
                'response_body' => $exception instanceof RequestException
                    ? $exception->response->json() ?: $exception->response->body()
                    : null,
            ]);

            return $this->result($credentialSource, null, $reason);
        }
    }

    /**
     * @param  array<string, mixed>  $extraMeta  merged into the base meta bag -- only Flutterwave's
     *                                           old initializeCheckout() ever sent credential_source/
     *                                           credential_label, so it's kept opt-in rather than
     *                                           changing what Monnify/Paystack/Squad already send
     */
    private function hotspotChargeRequest(Payment $payment, array $customer, array $extraMeta = []): ChargeRequest
    {
        return new ChargeRequest(
            reference: $payment->tx_ref,
            amount: (float) $payment->amount,
            currency: $payment->currency,
            redirectUrl: $this->callbackUrl($payment),
            customerEmail: GuestCustomerEmail::resolve($payment, $customer['email'] ?? null),
            customerName: (string) ($customer['name'] ?? 'Hotspot Customer'),
            description: $payment->package->name.' hotspot access',
            meta: array_merge([
                'payment_id' => $payment->id,
                'payment_reference' => $payment->tx_ref,
                'tenant_id' => $payment->shop->tenant_id,
                'tenant_name' => $payment->shop->tenant->company_name,
                'shop_id' => $payment->shop_id,
                'shop_name' => $payment->shop->name,
                'package_id' => $payment->package_id,
                'package_name' => $payment->package->name,
                'device_mac' => data_get($payment->payload, 'mac'),
                'nas_identifier' => data_get($payment->payload, 'nasid'),
                'phone' => (string) ($customer['phone'] ?? ''),
            ], $extraMeta),
            customerPhone: (string) ($customer['phone'] ?? ''),
            addressCity: $payment->shop->location_city ?: 'Lagos',
            addressState: $payment->shop->location_city ?: 'Lagos',
            addressLine1: $payment->shop->name,
        );
    }

    private function credentialSourceFor(Payment $payment, HostedGateway $gateway, GatewayCredentials $credentials, string $gatewayLabel): array
    {
        if ($payment->shop?->tenant?->wallet_enabled) {
            return [
                'source' => 'platform',
                'label' => 'MMS Radius platform gateway',
            ];
        }

        if ($gateway->isConfigured($credentials)) {
            return [
                'source' => 'tenant',
                'label' => $payment->shop->tenant->company_name.' / '.$payment->shop->name,
            ];
        }

        return [
            'source' => 'unconfigured',
            'label' => 'Tenant '.$gatewayLabel.' account not configured',
        ];
    }

    private function callbackUrl(Payment $payment): string
    {
        return match ($payment->provider) {
            // Monnify appends its own ?paymentReference=...&paymentStatus=... to
            // whatever redirectUrl it's given, using a plain "?" rather than
            // checking for an existing query string first -- pre-embedding our
            // own ?paymentReference=... here produced a doubled, malformed query
            // string on return (confirmed live 2026-09-25: the tx_ref PortalController
            // extracted literally contained a second, embedded "?paymentReference=..."
            // copy of itself, so payment lookup failed outright). callback()'s tx_ref
            // extraction already checks paymentReference as a fallback, so Monnify's
            // own appended value alone is all that's needed here.
            PaymentGatewayCatalog::MONNIFY => route('hotspot.payment.callback'),
            PaymentGatewayCatalog::SQUAD => route('hotspot.payment.callback', ['transaction_ref' => $payment->tx_ref]),
            PaymentGatewayCatalog::STRIPE, PaymentGatewayCatalog::FLUTTERWAVE => route('hotspot.payment.callback', ['tx_ref' => $payment->tx_ref]),
            default => route('hotspot.payment.callback'),
        };
    }

    private function checkoutFailureReason(Throwable $exception): string
    {
        if ($exception instanceof RequestException && $exception->response->status() === 401) {
            return 'invalid_gateway_secret_key';
        }

        return 'initialization_failed';
    }

    private function result(array $credentialSource, ?string $checkoutUrl, ?string $unavailableReason): array
    {
        return [
            'credential_source' => $credentialSource,
            'checkout_url' => $checkoutUrl,
            'unavailable_reason' => $unavailableReason,
        ];
    }
}
