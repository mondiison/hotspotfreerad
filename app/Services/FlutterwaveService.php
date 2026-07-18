<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FlutterwaveService
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(Payment $payment, array $customer, string $redirectUrl): array
    {
        $response = Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->withHeaders([
                'X-Trace-Id' => $payment->tx_ref,
                'X-Idempotency-Key' => $payment->tx_ref,
            ])
            ->post($this->baseUrl().'/orchestration/direct-orders', [
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->tx_ref,
                'redirect_url' => $redirectUrl,
                'payment_method' => (string) config('services.flutterwave.default_payment_method', 'opay'),
                'customer' => [
                    'email' => $customer['email'] ?: 'guest-'.$payment->id.'@hotspot.local',
                    'phone' => $customer['phone'] ?? null,
                    'name' => $customer['name'] ?? 'Hotspot Customer',
                ],
                'metadata' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                    'credential_source' => $this->credentialSource($payment)['source'],
                    'credential_label' => $this->credentialSource($payment)['label'],
                    'tenant_id' => $payment->shop->tenant_id,
                    'tenant_name' => $payment->shop->tenant->company_name,
                    'shop_id' => $payment->shop_id,
                    'shop_name' => $payment->shop->name,
                    'package_id' => $payment->package_id,
                    'package_name' => $payment->package->name,
                    'device_mac' => data_get($payment->payload, 'mac'),
                    'nas_identifier' => data_get($payment->payload, 'nasid'),
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
    public function verifyPayment(Payment $payment, string $providerReference, string $type = 'order'): array
    {
        $resource = Str::startsWith($type, 'charge') ? 'charges' : 'orders';

        return Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->get($this->baseUrl()."/{$resource}/{$providerReference}")
            ->throw()
            ->json();
    }

    public function webhookIsValid(?string $signature, ?Payment $payment = null): bool
    {
        $secretHash = $payment?->shop?->flutterwave_webhook_secret
            ?: config('services.flutterwave.webhook_secret_hash');

        return filled($secretHash) && hash_equals((string) $secretHash, (string) $signature);
    }

    public function isConfiguredFor(Payment $payment): bool
    {
        return filled($this->clientId($payment)) && filled($this->clientSecret($payment));
    }

    public function credentialSource(Payment $payment): array
    {
        if (filled($payment->shop?->flutterwave_client_id) && filled($payment->shop?->flutterwave_client_secret)) {
            return [
                'source' => 'tenant',
                'label' => $payment->shop->tenant->company_name.' / '.$payment->shop->name,
            ];
        }

        return [
            'source' => 'platform',
            'label' => (string) config('app.name', 'Platform').' fallback account',
        ];
    }

    public function providerReference(array $response): ?string
    {
        foreach ([
            'data.id',
            'data.order.id',
            'data.order_id',
            'data.charge.id',
            'data.charge_id',
        ] as $key) {
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

    private function accessToken(Payment $payment): string
    {
        $clientId = $this->clientId($payment);
        $clientSecret = $this->clientSecret($payment);
        $cacheKey = 'flutterwave:v4:token:'.sha1($clientId.'|'.$clientSecret);

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

    private function clientId(Payment $payment): string
    {
        if ($this->credentialSource($payment)['source'] === 'tenant') {
            return (string) $payment->shop->flutterwave_client_id;
        }

        return (string) config('services.flutterwave.client_id');
    }

    private function clientSecret(Payment $payment): string
    {
        if ($this->credentialSource($payment)['source'] === 'tenant') {
            return (string) $payment->shop->flutterwave_client_secret;
        }

        return (string) config('services.flutterwave.client_secret');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.flutterwave.base_url'), '/');
    }
}
