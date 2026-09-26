<?php

namespace App\Services;

use App\Models\PlatformBillingPayment;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Platform-scoped mirror of SquadService -- same API shape, but reading
 * credentials from PlatformPaymentSettingsService::gatewaySettings('squad')
 * instead of a shop's own paymentGatewaySettings(), and charging a
 * PlatformBillingPayment (tenant paying HotspotFreeRAD) rather than a
 * hotspot customer's Payment.
 */
class PlatformSquadService
{
    public function __construct(private readonly PlatformPaymentSettingsService $settings) {}

    /**
     * @throws RequestException
     */
    public function initializeCheckout(PlatformBillingPayment $payment, string $redirectUrl): array
    {
        $response = Http::withToken($this->secretKey())
            ->acceptJson()
            ->post($this->baseUrl().'/transaction/initiate', [
                'amount' => $this->amountInKobo($payment),
                'transaction_ref' => $payment->tx_ref,
                'email' => $payment->tenant->owner_email,
                'currency' => $payment->currency,
                'callback_url' => $redirectUrl,
                'customer_name' => $payment->tenant->company_name,
                'initiate_type' => 'inline',
                'payment_channels' => ['card', 'bank', 'ussd', 'transfer'],
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
            'provider_reference' => (string) (data_get($response, 'data.transaction_ref') ?: $payment->tx_ref),
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
        foreach (['data.checkout_url', 'data.checkoutUrl', 'checkout_url'] as $key) {
            $value = data_get($response, $key);

            if (filled($value) && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private function secretKey(): string
    {
        return (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::SQUAD)['secret_key'] ?? '');
    }

    /**
     * Defaults to "test" (sandbox) so a platform that's never touched this
     * setting keeps behaving exactly as it always has, matching SquadService's
     * own default for the tenant-facing side.
     */
    private function baseUrl(): string
    {
        $environment = (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::SQUAD)['environment'] ?? 'test');

        return $environment === 'live'
            ? rtrim((string) config('services.squad.live_base_url'), '/')
            : rtrim((string) config('services.squad.base_url'), '/');
    }

    private function amountInKobo(PlatformBillingPayment $payment): int
    {
        return (int) round(((float) $payment->amount) * 100);
    }
}
