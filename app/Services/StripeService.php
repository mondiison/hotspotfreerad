<?php

namespace App\Services;

use App\Models\Payment;
use App\Services\Payments\Contracts\HotspotHostedGateway;
use App\Support\GuestCustomerEmail;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StripeService implements HotspotHostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(Payment $payment, array $customer, string $callbackUrl): array
    {
        $response = Http::withToken($this->secretKey($payment))
            ->asForm()
            ->acceptJson()
            ->post($this->baseUrl().'/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $callbackUrl.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('hotspot.payment.callback', [
                    'tx_ref' => $payment->tx_ref,
                    'status' => 'cancelled',
                ]),
                'client_reference_id' => $payment->tx_ref,
                'customer_email' => GuestCustomerEmail::resolve($payment, $customer['email'] ?? null),
                'currency' => strtolower($payment->currency),
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($payment->currency),
                        'unit_amount' => $this->amountInSmallestUnit($payment),
                        'product_data' => [
                            'name' => $payment->package->name,
                            'description' => $payment->shop->name.' hotspot access',
                        ],
                    ],
                ]],
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
            'provider_reference' => (string) (data_get($response, 'id') ?: $payment->tx_ref),
            'checkout_url' => $this->checkoutUrl($response),
        ];
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(Payment $payment, ?string $sessionId = null): array
    {
        $sessionId = $sessionId ?: $payment->provider_reference ?: $payment->tx_ref;

        return Http::withToken($this->secretKey($payment))
            ->acceptJson()
            ->get($this->baseUrl().'/checkout/sessions/'.rawurlencode($sessionId))
            ->throw()
            ->json();
    }

    public function webhookIsValid(string $rawBody, ?string $signatureHeader, Payment $payment): bool
    {
        $secret = $this->webhookSecret($payment);

        if (blank($secret) || blank($signatureHeader)) {
            return false;
        }

        $timestamp = $this->signaturePart($signatureHeader, 't');
        $signature = $this->signaturePart($signatureHeader, 'v1');

        if (blank($timestamp) || blank($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, (string) $signature);
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
            'label' => 'Tenant Stripe account not configured',
        ];
    }

    public function checkoutUrl(array $response): ?string
    {
        $value = data_get($response, 'url');

        return filled($value) && is_string($value) ? $value : null;
    }

    private function secretKey(Payment $payment): string
    {
        $settings = (array) ($payment->shop?->paymentGatewaySettings()['stripe'] ?? []);

        return $this->normalizeSecret((string) ($settings['secret_key'] ?? ''));
    }

    private function webhookSecret(Payment $payment): string
    {
        $settings = (array) ($payment->shop?->paymentGatewaySettings()['stripe'] ?? []);

        return $this->normalizeSecret((string) ($settings['webhook_secret'] ?? ''));
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

    private function amountInSmallestUnit(Payment $payment): int
    {
        return (int) round(((float) $payment->amount) * 100);
    }
}
