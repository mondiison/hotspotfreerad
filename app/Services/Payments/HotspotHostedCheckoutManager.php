<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\MonnifyService;
use App\Services\PaystackService;
use App\Services\Payments\Contracts\HotspotHostedGateway;
use App\Services\SquadService;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class HotspotHostedCheckoutManager
{
    public function __construct(
        private readonly MonnifyService $monnify,
        private readonly PaystackService $paystack,
        private readonly SquadService $squad,
    ) {}

    public function supports(Payment $payment): bool
    {
        return in_array($payment->provider, [
            PaymentGatewayCatalog::MONNIFY,
            PaymentGatewayCatalog::PAYSTACK,
            PaymentGatewayCatalog::SQUAD,
        ], true);
    }

    /**
     * @return array{credential_source: array<string, string>, checkout_url: ?string, unavailable_reason: ?string}
     */
    public function start(Payment $payment, array $customer): array
    {
        $gateway = $this->gatewayFor($payment);
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

    private function gatewayFor(Payment $payment): HotspotHostedGateway
    {
        return match ($payment->provider) {
            PaymentGatewayCatalog::MONNIFY => $this->monnify,
            PaymentGatewayCatalog::SQUAD => $this->squad,
            default => $this->paystack,
        };
    }

    private function callbackUrl(Payment $payment): string
    {
        return match ($payment->provider) {
            PaymentGatewayCatalog::MONNIFY => route('hotspot.payment.callback', ['paymentReference' => $payment->tx_ref]),
            PaymentGatewayCatalog::SQUAD => route('hotspot.payment.callback', ['transaction_ref' => $payment->tx_ref]),
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
