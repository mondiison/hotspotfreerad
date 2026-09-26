<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\Contracts\HostedGateway;
use App\Services\Payments\GatewayCredentials;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The one Stripe integration, used by both tenant hotspot checkout
 * (HotspotHostedCheckoutManager) and platform billing checkout
 * (PlatformHostedCheckoutManager) -- the fifth and last of the five gateways
 * to migrate, expected to be one of the two hardest (alongside Flutterwave)
 * since Checkout Sessions are a meaningfully different shape from every
 * other gateway's plain "initiate, get a redirect URL back" flow. In
 * practice it turned out to fit HostedGateway/ChargeRequest/ChargeResult
 * cleanly with two small additions (see ChargeRequest's own docblock):
 * a separate cancel_url (Stripe requires one distinct from success_url,
 * unlike every other gateway's single redirect URL) and a bare product
 * name distinct from the longer per-gateway `description` field. Both
 * original classes -- StripeService (tenant) and PlatformStripeService
 * (platform) -- are deleted entirely, since nothing else in this codebase
 * depended on either beyond checkout.
 */
class StripeGateway implements HostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(GatewayCredentials $credentials, ChargeRequest $request): ChargeResult
    {
        $response = Http::withToken($this->secretKey($credentials))
            ->asForm()
            ->acceptJson()
            ->post($this->baseUrl().'/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $request->redirectUrl.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $request->cancelUrl,
                'client_reference_id' => $request->reference,
                'customer_email' => $request->customerEmail,
                'currency' => strtolower($request->currency),
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'unit_amount' => $this->amountInSmallestUnit($request->amount),
                        'product_data' => [
                            'name' => $request->productName ?: $request->description,
                            'description' => $request->description,
                        ],
                    ],
                ]],
                'metadata' => $request->meta,
            ])
            ->throw()
            ->json();

        return new ChargeResult(
            response: $response,
            providerReference: (string) (data_get($response, 'id') ?: $request->reference),
            checkoutUrl: $this->checkoutUrl($response),
        );
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(GatewayCredentials $credentials, string $reference): array
    {
        return Http::withToken($this->secretKey($credentials))
            ->acceptJson()
            ->get($this->baseUrl().'/checkout/sessions/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function isConfigured(GatewayCredentials $credentials): bool
    {
        return $credentials->has('secret_key');
    }

    public function webhookIsValid(GatewayCredentials $credentials, string $rawBody, ?string $signature): bool
    {
        $secret = $this->webhookSecret($credentials);

        if (blank($secret) || blank($signature)) {
            return false;
        }

        $timestamp = $this->signaturePart($signature, 't');
        $v1 = $this->signaturePart($signature, 'v1');

        if (blank($timestamp) || blank($v1)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), (string) $v1);
    }

    public function checkoutUrl(array $response): ?string
    {
        $value = data_get($response, 'url');

        return filled($value) && is_string($value) ? $value : null;
    }

    private function secretKey(GatewayCredentials $credentials): string
    {
        return $this->normalizeSecret((string) $credentials->get('secret_key'));
    }

    private function webhookSecret(GatewayCredentials $credentials): string
    {
        return $this->normalizeSecret((string) $credentials->get('webhook_secret'));
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

    private function amountInSmallestUnit(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
