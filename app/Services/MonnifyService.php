<?php

namespace App\Services;

use App\Models\Payment;
use App\Services\Payments\Contracts\HotspotHostedGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MonnifyService implements HotspotHostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(Payment $payment, array $customer, string $redirectUrl): array
    {
        $response = Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->post($this->baseUrl().'/api/v1/merchant/transactions/init-transaction', [
                'amount' => (float) $payment->amount,
                'customerName' => (string) ($customer['name'] ?? 'Hotspot Customer'),
                'customerEmail' => $customer['email'] ?: 'guest-'.$payment->id.'@hotspot.local',
                'paymentReference' => $payment->tx_ref,
                'paymentDescription' => $payment->package->name.' hotspot access',
                'currencyCode' => $payment->currency,
                'contractCode' => $this->contractCode($payment),
                'redirectUrl' => $redirectUrl,
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER'],
                'metadata' => [
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
                ],
            ])
            ->throw()
            ->json();

        return [
            'response' => $response,
            'provider_reference' => (string) (data_get($response, 'responseBody.transactionReference') ?: $payment->tx_ref),
            'checkout_url' => $this->checkoutUrl($response),
        ];
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(Payment $payment, ?string $reference = null): array
    {
        $reference = $reference ?: $payment->provider_reference ?: $payment->tx_ref;
        $queryByPaymentReference = $reference === $payment->tx_ref || ! Str::startsWith($reference, 'MNFY|');

        $request = Http::withToken($this->accessToken($payment))->acceptJson();

        if ($queryByPaymentReference) {
            return $request
                ->get($this->baseUrl().'/api/v2/merchant/transactions/query', [
                    'paymentReference' => $reference,
                ])
                ->throw()
                ->json();
        }

        return $request
            ->get($this->baseUrl().'/api/v2/transactions/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function webhookIsValid(string $rawBody, ?string $signature, Payment $payment): bool
    {
        $secretKey = $this->secretKey($payment);

        if (blank($secretKey) || blank($signature)) {
            return false;
        }

        $signature = (string) $signature;

        return hash_equals(hash('sha512', $secretKey.$rawBody), $signature)
            || hash_equals(hash_hmac('sha512', $rawBody, $secretKey), $signature);
    }

    public function isConfiguredFor(Payment $payment): bool
    {
        return filled($this->apiKey($payment))
            && filled($this->secretKey($payment))
            && filled($this->contractCode($payment));
    }

    public function credentialSource(Payment $payment): array
    {
        if ($this->isConfiguredFor($payment)) {
            return [
                'source' => 'tenant',
                'label' => $payment->shop->tenant->company_name.' / '.$payment->shop->name,
            ];
        }

        return [
            'source' => 'unconfigured',
            'label' => 'Tenant Monnify account not configured',
        ];
    }

    public function checkoutUrl(array $response): ?string
    {
        $value = data_get($response, 'responseBody.checkoutUrl');

        return filled($value) && is_string($value) ? $value : null;
    }

    private function accessToken(Payment $payment): string
    {
        $apiKey = $this->apiKey($payment);
        $secretKey = $this->secretKey($payment);
        $cacheKey = 'monnify:token:'.sha1($this->baseUrl().'|'.$apiKey.'|'.$secretKey);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($apiKey, $secretKey): string {
            $response = Http::withHeaders([
                'Authorization' => 'Basic '.base64_encode($apiKey.':'.$secretKey),
            ])
                ->acceptJson()
                ->post($this->baseUrl().'/api/v1/auth/login')
                ->throw()
                ->json();

            return (string) data_get($response, 'responseBody.accessToken');
        });
    }

    private function apiKey(Payment $payment): string
    {
        return $this->setting($payment, 'public_key');
    }

    private function secretKey(Payment $payment): string
    {
        return $this->setting($payment, 'secret_key');
    }

    private function contractCode(Payment $payment): string
    {
        return $this->setting($payment, 'contract_code');
    }

    private function setting(Payment $payment, string $key): string
    {
        $settings = (array) ($payment->shop?->paymentGatewaySettings()['monnify'] ?? []);

        return trim((string) ($settings[$key] ?? ''), " \t\n\r\0\x0B\"'");
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.monnify.base_url'), '/');
    }
}
