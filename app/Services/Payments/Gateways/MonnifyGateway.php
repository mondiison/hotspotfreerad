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
 * The one Monnify integration, used by both tenant hotspot checkout
 * (HotspotHostedCheckoutManager) and platform billing checkout
 * (PlatformHostedCheckoutManager) -- previously MonnifyService (tenant) and
 * PlatformMonnifyService (platform) duplicated this exact HTTP shape,
 * differing only in where credentials came from and what was being charged.
 * PlatformMonnifyService itself is left in place, unrefactored -- it still
 * backs BankAccountResolutionService's banks()/resolveAccount() calls, which
 * are unrelated to checkout and out of scope here.
 */
class MonnifyGateway implements HostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(GatewayCredentials $credentials, ChargeRequest $request): ChargeResult
    {
        $response = Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->post($this->baseUrl($credentials).'/api/v1/merchant/transactions/init-transaction', [
                'amount' => $request->amount,
                'customerName' => $request->customerName,
                'customerEmail' => $request->customerEmail,
                'paymentReference' => $request->reference,
                'paymentDescription' => $request->description,
                'currencyCode' => $request->currency,
                'contractCode' => $credentials->get('contract_code'),
                'redirectUrl' => $request->redirectUrl,
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER'],
                'metadata' => $request->meta,
            ])
            ->throw()
            ->json();

        return new ChargeResult(
            response: $response,
            providerReference: (string) (data_get($response, 'responseBody.transactionReference') ?: $request->reference),
            checkoutUrl: $this->checkoutUrl($response),
        );
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(GatewayCredentials $credentials, string $reference): array
    {
        $queryByPaymentReference = ! Str::startsWith($reference, 'MNFY|');

        $httpRequest = Http::withToken($this->accessToken($credentials))->acceptJson();

        if ($queryByPaymentReference) {
            return $httpRequest
                ->get($this->baseUrl($credentials).'/api/v2/merchant/transactions/query', [
                    'paymentReference' => $reference,
                ])
                ->throw()
                ->json();
        }

        return $httpRequest
            ->get($this->baseUrl($credentials).'/api/v2/transactions/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function isConfigured(GatewayCredentials $credentials): bool
    {
        return $credentials->has('public_key')
            && $credentials->has('secret_key')
            && $credentials->has('contract_code');
    }

    /**
     * Monnify's webhook signature and its checkout auth both use the same
     * secret_key -- there is no separate webhook secret field for this
     * gateway, unlike Flutterwave/Stripe.
     */
    public function webhookIsValid(GatewayCredentials $credentials, string $rawBody, ?string $signature): bool
    {
        $secretKey = $credentials->get('secret_key');

        if (blank($secretKey) || blank($signature)) {
            return false;
        }

        $signature = (string) $signature;

        return hash_equals(hash('sha512', $secretKey.$rawBody), $signature)
            || hash_equals(hash_hmac('sha512', $rawBody, $secretKey), $signature);
    }

    public function checkoutUrl(array $response): ?string
    {
        $value = data_get($response, 'responseBody.checkoutUrl');

        return filled($value) && is_string($value) ? $value : null;
    }

    private function accessToken(GatewayCredentials $credentials): string
    {
        $apiKey = (string) $credentials->get('public_key');
        $secretKey = (string) $credentials->get('secret_key');
        $baseUrl = $this->baseUrl($credentials);
        $cacheKey = 'monnify:token:'.sha1($baseUrl.'|'.$apiKey.'|'.$secretKey);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($apiKey, $secretKey, $baseUrl): string {
            $response = Http::withHeaders([
                'Authorization' => 'Basic '.base64_encode($apiKey.':'.$secretKey),
            ])
                ->acceptJson()
                ->post($baseUrl.'/api/v1/auth/login')
                ->throw()
                ->json();

            return (string) data_get($response, 'responseBody.accessToken');
        });
    }

    /**
     * Defaults to "test" (sandbox) so an account that's never touched this
     * setting keeps behaving exactly as it always has, rather than silently
     * starting to hit the live API.
     */
    private function baseUrl(GatewayCredentials $credentials): string
    {
        $environment = $credentials->get('environment') ?: 'test';

        return $environment === 'live'
            ? rtrim((string) config('services.monnify.live_base_url'), '/')
            : rtrim((string) config('services.monnify.base_url'), '/');
    }
}
