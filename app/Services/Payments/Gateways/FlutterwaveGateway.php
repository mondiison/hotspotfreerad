<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\Contracts\HostedGateway;
use App\Services\Payments\GatewayCredentials;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The OPay slice of Flutterwave, used by both tenant hotspot checkout
 * (HotspotHostedCheckoutManager::startFlutterwaveOpay()) and platform
 * billing checkout (PlatformHostedCheckoutManager::start()) -- Flutterwave's
 * v4 orchestration "/orchestration/direct-charges" direct-charge endpoint.
 *
 * Deliberately scoped to OPay only (2026-09-26, direct decision after
 * confirming Flutterwave doesn't fit the same "one class, one API shape"
 * pattern Monnify/Paystack/Squad did): card checkout is a genuinely
 * different API (v3 hosted `/payments`, its own Secret Key credential) that
 * was already special-cased outside the normal checkout dispatch before
 * this migration, and bank transfer returns a virtual account number/bank
 * name, not a checkout URL, so it doesn't fit ChargeResult's shape at all.
 * FlutterwaveService (tenant) and PlatformFlutterwaveService (platform)
 * both survive, stripped only of the OPay-specific initializeCheckout()
 * logic this class now owns -- they still handle card, bank transfer,
 * webhook signature validation, and (platform side) banks()/resolveAccount().
 */
class FlutterwaveGateway implements HostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(GatewayCredentials $credentials, ChargeRequest $request): ChargeResult
    {
        $response = Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->withHeaders([
                'X-Trace-Id' => $request->reference,
                'X-Idempotency-Key' => $request->reference,
            ])
            ->post($this->baseUrl().'/orchestration/direct-charges', [
                'amount' => $request->amount,
                'currency' => $request->currency,
                'reference' => $request->reference,
                'redirect_url' => $request->redirectUrl,
                'payment_method' => ['type' => 'opay'],
                'customer' => $this->customerPayload($request),
                'meta' => $request->meta,
            ])
            ->throw()
            ->json();

        return new ChargeResult(
            response: $response,
            providerReference: $this->providerReference($response),
            checkoutUrl: $this->checkoutUrl($response),
        );
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(GatewayCredentials $credentials, string $reference, string $type = 'order'): array
    {
        $resource = Str::startsWith($type, 'order') ? 'orders' : 'charges';

        return Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->get($this->baseUrl()."/{$resource}/{$reference}")
            ->throw()
            ->json();
    }

    public function isConfigured(GatewayCredentials $credentials): bool
    {
        return $credentials->has('client_id') && $credentials->has('client_secret');
    }

    /**
     * Flutterwave's own `verif-hash` webhook header is compared directly
     * against the stored Secret Hash -- unlike Monnify/Paystack/Squad, it's
     * not an HMAC computed over the raw body, so $rawBody goes unused here.
     * Implemented for interface completeness; not yet wired into any
     * caller (PortalController::webhook()'s Flutterwave branch still calls
     * the original FlutterwaveService::webhookIsValid(), unchanged) since
     * this migration is scoped to checkout initiation only.
     */
    public function webhookIsValid(GatewayCredentials $credentials, string $rawBody, ?string $signature): bool
    {
        $secretHash = $credentials->get('webhook_secret');

        return filled($secretHash) && filled($signature) && hash_equals((string) $secretHash, (string) $signature);
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

    private function providerReference(array $response): ?string
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

    private function customerPayload(ChargeRequest $request): array
    {
        [$firstName, $lastName] = $this->splitName($request->customerName);
        [$countryCode, $phoneNumber] = $this->phoneParts($request->customerPhone);

        return [
            'email' => $request->customerEmail,
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
                'city' => $request->addressCity ?: 'Lagos',
                'state' => $request->addressState ?: 'Lagos',
                'postal_code' => '100001',
                'line1' => $request->addressLine1,
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

    private function accessToken(GatewayCredentials $credentials): string
    {
        $clientId = (string) $credentials->get('client_id');
        $clientSecret = (string) $credentials->get('client_secret');
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

    private function baseUrl(): string
    {
        return rtrim((string) config('services.flutterwave.base_url'), '/');
    }
}
