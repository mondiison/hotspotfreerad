<?php

namespace App\Services;

use App\Models\PlatformBillingPayment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PlatformStripeService
{
    public function __construct(private readonly PlatformPaymentSettingsService $settings) {}

    /**
     * @throws RequestException
     */
    public function initializeCheckout(PlatformBillingPayment $payment, string $redirectUrl): array
    {
        $response = Http::withToken($this->secretKey())
            ->asForm()
            ->acceptJson()
            ->post($this->baseUrl().'/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $redirectUrl.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('admin.billing.index'),
                'client_reference_id' => $payment->tx_ref,
                'customer_email' => $payment->tenant->owner_email,
                'currency' => strtolower($payment->currency),
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($payment->currency),
                        'unit_amount' => (int) round(((float) $payment->amount) * 100),
                        'product_data' => [
                            'name' => $payment->billingPlan->name,
                            'description' => 'MMS Radius platform subscription',
                        ],
                    ],
                ]],
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
            'provider_reference' => (string) (data_get($response, 'id') ?: $payment->tx_ref),
            'checkout_url' => data_get($response, 'url'),
        ];
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(string $sessionId): array
    {
        return Http::withToken($this->secretKey())
            ->acceptJson()
            ->get($this->baseUrl().'/checkout/sessions/'.rawurlencode($sessionId))
            ->throw()
            ->json();
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey());
    }

    public function webhookIsValid(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = $this->webhookSecret();

        if (blank($secret) || blank($signatureHeader)) {
            return false;
        }

        $timestamp = $this->signaturePart($signatureHeader, 't');
        $signature = $this->signaturePart($signatureHeader, 'v1');

        if (blank($timestamp) || blank($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), (string) $signature);
    }

    private function secretKey(): string
    {
        return $this->normalizeSecret((string) $this->settings->clientSecret());
    }

    private function webhookSecret(): string
    {
        return $this->normalizeSecret((string) $this->settings->webhookSecretHash());
    }

    private function normalizeSecret(string $secret): string
    {
        $secret = trim($secret, " \t\n\r\0\x0B\"'");

        if (Str::startsWith(Str::lower($secret), 'bearer ')) {
            return trim(substr($secret, 7), " \t\n\r\0\x0B\"'");
        }

        return $secret;
    }

    private function signaturePart(string $header, string $key): ?string
    {
        foreach (explode(',', $header) as $part) {
            [$partKey, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($partKey === $key && filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.stripe.base_url'), '/');
    }
}
