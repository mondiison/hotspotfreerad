<?php

namespace App\Services;

use App\Models\Payment;
use App\Services\Payments\Contracts\HotspotHostedGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SquadService implements HotspotHostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(Payment $payment, array $customer, string $callbackUrl): array
    {
        $response = Http::withToken($this->secretKey($payment))
            ->acceptJson()
            ->post($this->baseUrl().'/transaction/initiate', [
                'amount' => $this->amountInKobo($payment),
                'transaction_ref' => $payment->tx_ref,
                'email' => $customer['email'] ?: 'guest-'.$payment->id.'@hotspot.local',
                'currency' => $payment->currency,
                'callback_url' => $callbackUrl,
                'customer_name' => (string) ($customer['name'] ?? 'Hotspot Customer'),
                'initiate_type' => 'inline',
                'payment_channels' => ['card', 'bank', 'ussd', 'transfer'],
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
            'provider_reference' => (string) (data_get($response, 'data.transaction_ref') ?: $payment->tx_ref),
            'checkout_url' => $this->checkoutUrl($response),
        ];
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(Payment $payment, ?string $reference = null): array
    {
        $reference = $reference ?: $payment->provider_reference ?: $payment->tx_ref;

        return Http::withToken($this->secretKey($payment))
            ->acceptJson()
            ->get($this->baseUrl().'/transaction/verify/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function webhookIsValid(string $rawBody, ?string $signature, Payment $payment): bool
    {
        $secretKey = $this->secretKey($payment);

        return filled($secretKey)
            && filled($signature)
            && hash_equals(hash_hmac('sha512', $rawBody, $secretKey), (string) $signature);
    }

    public function isConfiguredFor(Payment $payment): bool
    {
        return filled($this->secretKey($payment));
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
            'label' => 'Tenant Squad account not configured',
        ];
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

    private function secretKey(Payment $payment): string
    {
        $settings = (array) ($payment->shop?->paymentGatewaySettings()['squad'] ?? []);
        $secretKey = trim((string) ($settings['secret_key'] ?? ''), " \t\n\r\0\x0B\"'");

        if (Str::startsWith(Str::lower($secretKey), 'bearer ')) {
            return trim(substr($secretKey, 7), " \t\n\r\0\x0B\"'");
        }

        return $secretKey;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.squad.base_url'), '/');
    }

    private function amountInKobo(Payment $payment): int
    {
        return (int) round(((float) $payment->amount) * 100);
    }
}
