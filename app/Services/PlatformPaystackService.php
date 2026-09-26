<?php

namespace App\Services;

use App\Models\PlatformBillingPayment;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Platform-scoped mirror of PaystackService -- same API shape, but reading
 * credentials from PlatformPaymentSettingsService::gatewaySettings('paystack')
 * instead of a shop's own paymentGatewaySettings(), and charging a
 * PlatformBillingPayment (tenant paying HotspotFreeRAD) rather than a
 * hotspot customer's Payment.
 */
class PlatformPaystackService
{
    public function __construct(private readonly PlatformPaymentSettingsService $settings) {}

    /**
     * @throws RequestException
     */
    public function initializeCheckout(PlatformBillingPayment $payment, string $redirectUrl): array
    {
        $response = Http::withToken($this->secretKey())
            ->acceptJson()
            ->post($this->baseUrl().'/transaction/initialize', [
                'email' => $payment->tenant->owner_email,
                'amount' => $this->amountInSubunit($payment),
                'currency' => $payment->currency,
                'reference' => $payment->tx_ref,
                'callback_url' => $redirectUrl,
                'metadata' => [
                    'payment_type' => 'platform_subscription',
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                    'tenant_id' => $payment->tenant_id,
                    'tenant_name' => $payment->tenant->company_name,
                    'billing_plan_id' => $payment->billing_plan_id,
                    'billing_plan_name' => $payment->billingPlan->name,
                ],
            ])
            ->throw()
            ->json();

        return [
            'response' => $response,
            'provider_reference' => (string) (data_get($response, 'data.reference') ?: $payment->tx_ref),
            'checkout_url' => $this->checkoutUrl($response),
        ];
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(string $reference): array
    {
        return Http::withToken($this->secretKey())
            ->acceptJson()
            ->get($this->baseUrl().'/transaction/verify/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey());
    }

    public function webhookIsValid(string $rawBody, ?string $signature): bool
    {
        $secretKey = $this->secretKey();

        return filled($secretKey)
            && filled($signature)
            && hash_equals(hash_hmac('sha512', $rawBody, $secretKey), (string) $signature);
    }

    public function checkoutUrl(array $response): ?string
    {
        $value = data_get($response, 'data.authorization_url');

        return filled($value) && is_string($value) ? $value : null;
    }

    private function secretKey(): string
    {
        return (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::PAYSTACK)['secret_key'] ?? '');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.paystack.base_url'), '/');
    }

    private function amountInSubunit(PlatformBillingPayment $payment): int
    {
        return (int) round(((float) $payment->amount) * 100);
    }
}
