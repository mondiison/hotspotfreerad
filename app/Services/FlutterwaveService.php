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
            ->post($this->baseUrl().'/orchestration/direct-charges', [
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->tx_ref,
                'redirect_url' => $redirectUrl,
                'payment_method' => [
                    'type' => $this->paymentMethodType($customer['payment_method'] ?? null),
                ],
                'customer' => $this->customerPayload($payment, $customer),
                'meta' => [
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
    public function createDynamicVirtualAccount(Payment $payment, array $customer): array
    {
        $customerResponse = $this->createCustomer($payment, $customer);
        $customerId = data_get($customerResponse, 'data.id');

        $response = Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->withHeaders([
                'X-Trace-Id' => $payment->tx_ref,
                'X-Idempotency-Key' => $payment->tx_ref.'-virtual-account',
            ])
            ->post($this->baseUrl().'/virtual-accounts', [
                'reference' => $payment->tx_ref,
                'customer_id' => $customerId,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'account_type' => 'dynamic',
                'expiry' => 3600,
                'narration' => $payment->shop->name.' hotspot',
                'meta' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                    'shop_id' => $payment->shop_id,
                    'package_id' => $payment->package_id,
                    'device_mac' => data_get($payment->payload, 'mac'),
                    'nas_identifier' => data_get($payment->payload, 'nasid'),
                ],
            ])
            ->throw()
            ->json();

        return [
            'response' => $response,
            'customer_response' => $customerResponse,
            'customer_id' => (string) $customerId,
            'virtual_account_id' => (string) data_get($response, 'data.id'),
            'account_number' => (string) data_get($response, 'data.account_number'),
            'bank_name' => (string) data_get($response, 'data.account_bank_name'),
            'account_name' => (string) (data_get($response, 'data.narration') ?: data_get($response, 'data.note')),
            'expires_at' => data_get($response, 'data.account_expiration_datetime'),
            'note' => data_get($response, 'data.note'),
        ];
    }

    /**
     * @throws RequestException
     */
    public function createCheckoutSession(Payment $payment, array $customer, string $redirectUrl): array
    {
        $customerResponse = $this->createCustomer($payment, $customer);
        $customerId = data_get($customerResponse, 'data.id');

        $response = Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->withHeaders([
                'X-Trace-Id' => $payment->tx_ref,
                'X-Idempotency-Key' => $payment->tx_ref.'-checkout-session',
            ])
            ->post($this->baseUrl().'/checkout/sessions', [
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'customer_id' => $customerId,
                'redirect_url' => $redirectUrl,
                'reference' => $payment->tx_ref,
                'max_retry_attempts' => 3,
                'session_duration' => 30,
            ])
            ->throw()
            ->json();

        return [
            'response' => $response,
            'customer_response' => $customerResponse,
            'customer_id' => (string) $customerId,
            'provider_reference' => (string) data_get($response, 'data.id'),
            'checkout_url' => $this->hostedCheckoutUrl($response),
        ];
    }

    /**
     * @throws RequestException
     */
    public function virtualAccountCharges(Payment $payment, string $virtualAccountId): array
    {
        return Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->get($this->baseUrl().'/charges', [
                'virtual_account_id' => $virtualAccountId,
            ])
            ->throw()
            ->json();
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(Payment $payment, string $providerReference, string $type = 'order'): array
    {
        $resource = Str::startsWith($type, 'order') ? 'orders' : 'charges';

        return Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->get($this->baseUrl()."/{$resource}/{$providerReference}")
            ->throw()
            ->json();
    }

    public function webhookIsValid(?string $signature, ?Payment $payment = null): bool
    {
        $secretHash = $payment?->shop?->flutterwave_webhook_secret;

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
            'source' => 'unconfigured',
            'label' => 'Tenant payment account not configured',
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
            'data.checkout_url',
            'data.link',
            'data.next_action.redirect_url.url',
            'data.next_action.redirect_url',
            'data.redirect_url.url',
            'data.redirect_url',
        ] as $key) {
            $value = data_get($response, $key);

            if (filled($value) && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    public function hostedCheckoutUrl(array $response): ?string
    {
        foreach ([
            'data.checkout_url',
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

    private function createCustomer(Payment $payment, array $customer): array
    {
        return Http::withToken($this->accessToken($payment))
            ->acceptJson()
            ->withHeaders([
                'X-Trace-Id' => $payment->tx_ref,
                'X-Idempotency-Key' => $payment->tx_ref.'-customer',
            ])
            ->post($this->baseUrl().'/customers', $this->customerPayload($payment, $customer))
            ->throw()
            ->json();
    }

    private function clientId(Payment $payment): string
    {
        if ($this->credentialSource($payment)['source'] === 'tenant') {
            return (string) $payment->shop->flutterwave_client_id;
        }

        return '';
    }

    private function clientSecret(Payment $payment): string
    {
        if ($this->credentialSource($payment)['source'] === 'tenant') {
            return (string) $payment->shop->flutterwave_client_secret;
        }

        return '';
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.flutterwave.base_url'), '/');
    }

    private function paymentMethodType(?string $selectedMethod = null): string
    {
        $method = $selectedMethod ?: config('services.flutterwave.default_payment_method');

        return filled($method)
            ? (string) $method
            : 'opay';
    }

    private function customerPayload(Payment $payment, array $customer): array
    {
        [$firstName, $lastName] = $this->splitName((string) ($customer['name'] ?? 'Hotspot Customer'));
        [$countryCode, $phoneNumber] = $this->phoneParts((string) ($customer['phone'] ?? ''));

        return [
            'email' => $customer['email'] ?: 'guest-'.$payment->id.'@hotspot.local',
            'name' => [
                'first' => $firstName,
                'last' => $lastName,
            ],
            'phone' => [
                'country_code' => $countryCode,
                'number' => $phoneNumber,
            ],
            'address' => [
                'country' => 'NG',
                'city' => $payment->shop->location_city ?: 'Lagos',
                'state' => $payment->shop->location_city ?: 'Lagos',
                'postal_code' => '100001',
                'line1' => $payment->shop->name,
            ],
        ];
    }

    private function splitName(string $name): array
    {
        $parts = Str::of($name)->squish()->explode(' ')->filter()->values();

        return [
            (string) ($parts->first() ?: 'Hotspot'),
            (string) ($parts->skip(1)->implode(' ') ?: 'Customer'),
        ];
    }

    private function phoneParts(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '8000000000';

        if (Str::startsWith($digits, '234') && strlen($digits) > 10) {
            return ['234', substr($digits, 3)];
        }

        if (Str::startsWith($digits, '0') && strlen($digits) > 1) {
            return ['234', substr($digits, 1)];
        }

        return ['234', $digits];
    }
}
