<?php

namespace App\Services;

use App\Models\PlatformBillingPayment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PlatformFlutterwaveService
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(PlatformBillingPayment $payment, string $redirectUrl): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([
                'X-Trace-Id' => $payment->tx_ref,
                'X-Idempotency-Key' => $payment->tx_ref,
            ])
            ->post($this->baseUrl().'/orchestration/direct-charges', [
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->tx_ref,
                'redirect_url' => $redirectUrl,
                'payment_method' => [
                    'type' => (string) config('services.flutterwave.default_payment_method', 'opay'),
                ],
                'customer' => [
                    'email' => $payment->tenant->owner_email,
                    'name' => $payment->tenant->company_name,
                ],
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
            'provider_reference' => $this->providerReference($response),
            'checkout_url' => $this->checkoutUrl($response),
        ];
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(string $providerReference, string $type = 'order'): array
    {
        $resource = Str::startsWith($type, 'order') ? 'orders' : 'charges';

        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->baseUrl()."/{$resource}/{$providerReference}")
            ->throw()
            ->json();
    }

    public function isConfigured(): bool
    {
        return filled(config('services.flutterwave.client_id'))
            && filled(config('services.flutterwave.client_secret'));
    }

    public function webhookIsValid(string $rawBody, ?string $signature): bool
    {
        $secretHash = config('services.flutterwave.webhook_secret_hash');

        if (blank($secretHash) || blank($signature)) {
            return false;
        }

        $computedSignature = base64_encode(hash_hmac('sha256', $rawBody, (string) $secretHash, true));

        return hash_equals($computedSignature, (string) $signature)
            || hash_equals((string) $secretHash, (string) $signature);
    }

    public function providerReference(array $response): ?string
    {
        foreach (['data.id', 'data.order.id', 'data.order_id', 'data.charge.id', 'data.charge_id'] as $key) {
            $value = data_get($response, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    public function checkoutUrl(array $response): ?string
    {
        foreach ([
            'data.next_action.redirect_url.url',
            'data.next_action.redirect_url',
            'data.redirect_url.url',
            'data.redirect_url',
            'data.link',
        ] as $key) {
            $value = data_get($response, $key);

            if (filled($value) && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private function accessToken(): string
    {
        $clientId = (string) config('services.flutterwave.client_id');
        $clientSecret = (string) config('services.flutterwave.client_secret');
        $cacheKey = 'flutterwave:v4:platform-token:'.sha1($clientId.'|'.$clientSecret);

        return Cache::remember($cacheKey, now()->addMinutes(8), function () use ($clientId, $clientSecret): string {
            $response = Http::asForm()
                ->acceptJson()
                ->post((string) config('services.flutterwave.auth_url'), [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'client_credentials',
                ])
                ->throw()
                ->json();

            return (string) data_get($response, 'access_token');
        });
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.flutterwave.base_url'), '/');
    }
}
