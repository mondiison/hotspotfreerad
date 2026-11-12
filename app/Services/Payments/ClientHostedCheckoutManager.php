<?php

namespace App\Services\Payments;

use App\Models\ClientBillingPayment;
use App\Services\Payments\Contracts\HostedGateway;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\Gateways\SquadGateway;
use App\Services\Payments\Gateways\StripeGateway;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A Client paying its Tenant, mirroring PlatformHostedCheckoutManager's
 * shape almost line for line -- except there's no Flutterwave-card-vs-v4
 * split to worry about here at all: Flutterwave has no tenant-level
 * credential storage yet (confirmed by research before this shipped), so
 * this is scoped to Monnify/Paystack/Squad/Stripe only, every one of them
 * going through the exact same shared HostedGateway path.
 */
class ClientHostedCheckoutManager
{
    public function __construct(
        private readonly GatewayCredentialResolver $credentials,
        private readonly MonnifyGateway $monnifyGateway,
        private readonly PaystackGateway $paystackGateway,
        private readonly SquadGateway $squadGateway,
        private readonly StripeGateway $stripeGateway,
    ) {}

    public function isConfigured(ClientBillingPayment $payment, string $gateway): bool
    {
        return $this->gatewayFor($gateway)->isConfigured($this->credentialsFor($payment, $gateway));
    }

    /**
     * @return array{checkout_url: ?string, unavailable_reason: ?string}
     */
    public function start(ClientBillingPayment $payment): array
    {
        $payment->loadMissing(['client.tenant', 'clientBillingPlan']);
        $gateway = $payment->provider;
        $credentials = $this->credentialsFor($payment, $gateway);

        try {
            $chargeRequest = new ChargeRequest(
                reference: $payment->tx_ref,
                amount: (float) $payment->amount,
                currency: $payment->currency,
                redirectUrl: $this->redirectUrl($gateway, $payment),
                customerEmail: $payment->client->tenant->owner_email,
                customerName: $payment->client->name,
                description: $payment->clientBillingPlan->name.' subscription',
                meta: [
                    'payment_type' => 'client_subscription',
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                    'client_id' => $payment->client_id,
                    'client_name' => $payment->client->name,
                    'tenant_id' => $payment->client->tenant_id,
                    'client_billing_plan_id' => $payment->client_billing_plan_id,
                ],
            );

            $result = $this->gatewayFor($gateway)->initializeCheckout($credentials, $chargeRequest);

            $payment->update([
                'provider_reference' => $result->providerReference,
                'payload' => array_merge($payment->payload ?? [], [
                    'checkout_url' => $result->checkoutUrl,
                    'gateway_init_response' => $result->response,
                ]),
            ]);

            if (filled($result->checkoutUrl)) {
                return ['checkout_url' => $result->checkoutUrl, 'unavailable_reason' => null];
            }

            Log::warning('Client billing checkout response missing checkout URL', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'provider' => $gateway,
                'response_body' => $result->response,
            ]);

            return ['checkout_url' => null, 'unavailable_reason' => 'missing_checkout_url'];
        } catch (Throwable $exception) {
            Log::warning('Client billing checkout initialization failed', [
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

    public function webhookIsValid(ClientBillingPayment $payment, Request $request): bool
    {
        $gateway = $payment->provider;
        $credentials = $this->credentialsFor($payment, $gateway);

        return match ($gateway) {
            PaymentGatewayCatalog::STRIPE => $this->stripeGateway->webhookIsValid($credentials, $request->getContent(), $request->header('stripe-signature')),
            PaymentGatewayCatalog::MONNIFY => $this->monnifyGateway->webhookIsValid($credentials, $request->getContent(), $request->header('monnify-signature')),
            PaymentGatewayCatalog::PAYSTACK => $this->paystackGateway->webhookIsValid($credentials, $request->getContent(), $request->header('x-paystack-signature')),
            PaymentGatewayCatalog::SQUAD => $this->squadGateway->webhookIsValid($credentials, $request->getContent(), $request->header('x-squad-encrypted-body')),
            default => false,
        };
    }

    private function credentialsFor(ClientBillingPayment $payment, string $gateway): GatewayCredentials
    {
        $payment->loadMissing('client.tenant');

        return $this->credentials->forTenant($payment->client->tenant, $gateway);
    }

    private function gatewayFor(string $gateway): HostedGateway
    {
        return match ($gateway) {
            PaymentGatewayCatalog::MONNIFY => $this->monnifyGateway,
            PaymentGatewayCatalog::PAYSTACK => $this->paystackGateway,
            PaymentGatewayCatalog::SQUAD => $this->squadGateway,
            PaymentGatewayCatalog::STRIPE => $this->stripeGateway,
            default => throw new \InvalidArgumentException("Client checkout does not support gateway [{$gateway}]."),
        };
    }

    /**
     * Same reasoning as PlatformHostedCheckoutManager::redirectUrl() --
     * Monnify/Paystack append their own reference params to a bare
     * redirectUrl, Squad appends nothing so needs an explicit one embedded
     * under its own param name.
     */
    private function redirectUrl(string $gateway, ClientBillingPayment $payment): string
    {
        return match (true) {
            in_array($gateway, [PaymentGatewayCatalog::MONNIFY, PaymentGatewayCatalog::PAYSTACK], true) => route('client.billing.callback'),
            $gateway === PaymentGatewayCatalog::SQUAD => route('client.billing.callback', ['transaction_ref' => $payment->tx_ref]),
            default => route('client.billing.callback', ['tx_ref' => $payment->tx_ref]),
        };
    }
}
