<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\Contracts\HostedGateway;
use App\Services\Payments\GatewayCredentials;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The one Paystack integration, used by both tenant hotspot checkout
 * (HotspotHostedCheckoutManager) and platform billing checkout
 * (PlatformHostedCheckoutManager) -- previously PaystackService (tenant) and
 * PlatformPaystackService (platform) duplicated this exact HTTP shape,
 * differing only in where credentials came from and what was being charged.
 * Both original classes are deleted entirely -- unlike Monnify, nothing else
 * in this codebase depended on either of them for anything beyond checkout.
 */
class PaystackGateway implements HostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(GatewayCredentials $credentials, ChargeRequest $request): ChargeResult
    {
        $response = Http::withToken($this->secretKey($credentials))
            ->acceptJson()
            ->post($this->baseUrl().'/transaction/initialize', [
                'email' => $request->customerEmail,
                'amount' => $this->amountInSubunit($request->amount),
                'currency' => $request->currency,
                'reference' => $request->reference,
                'callback_url' => $request->redirectUrl,
                'metadata' => $request->meta,
            ])
            ->throw()
            ->json();

        return new ChargeResult(
            response: $response,
            providerReference: (string) (data_get($response, 'data.reference') ?: $request->reference),
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
            ->get($this->baseUrl().'/transaction/verify/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function isConfigured(GatewayCredentials $credentials): bool
    {
        return $credentials->has('secret_key');
    }

    public function webhookIsValid(GatewayCredentials $credentials, string $rawBody, ?string $signature): bool
    {
        $secretKey = $credentials->get('secret_key');

        return filled($secretKey)
            && filled($signature)
            && hash_equals(hash_hmac('sha512', $rawBody, $secretKey), (string) $signature);
    }

    public function checkoutUrl(array $response): ?string
    {
        $value = data_get($response, 'data.authorization_url');

        return filled($value) && is_string($value) ? $value : null;
    }

    private function secretKey(GatewayCredentials $credentials): string
    {
        return (string) $credentials->get('secret_key');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.paystack.base_url'), '/');
    }

    private function amountInSubunit(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
