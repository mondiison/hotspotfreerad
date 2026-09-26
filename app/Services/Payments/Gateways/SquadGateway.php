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
 * The one Squad integration, used by both tenant hotspot checkout
 * (HotspotHostedCheckoutManager) and platform billing checkout
 * (PlatformHostedCheckoutManager) -- previously SquadService (tenant) and
 * PlatformSquadService (platform) duplicated this exact HTTP shape,
 * differing only in where credentials came from and what was being charged.
 * Both original classes are deleted entirely -- nothing else in this
 * codebase depended on either of them for anything beyond checkout.
 *
 * secretKey()'s "Bearer " prefix strip fixes a real, if minor, asymmetry
 * found while consolidating: the tenant-facing SquadService already did
 * this, but PlatformSquadService never did, so a platform Squad secret key
 * pasted with a leading "Bearer " would have been sent to Squad literally
 * broken. Unifying onto the more complete tenant-side normalization is a
 * pure fix, not a behavior change anyone could have been relying on.
 */
class SquadGateway implements HostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(GatewayCredentials $credentials, ChargeRequest $request): ChargeResult
    {
        $response = Http::withToken($this->secretKey($credentials))
            ->acceptJson()
            ->post($this->baseUrl($credentials).'/transaction/initiate', [
                'amount' => $this->amountInKobo($request->amount),
                'transaction_ref' => $request->reference,
                'email' => $request->customerEmail,
                'currency' => $request->currency,
                'callback_url' => $request->redirectUrl,
                'customer_name' => $request->customerName,
                'initiate_type' => 'inline',
                'payment_channels' => ['card', 'bank', 'ussd', 'transfer'],
                'metadata' => $request->meta,
            ])
            ->throw()
            ->json();

        return new ChargeResult(
            response: $response,
            providerReference: (string) (data_get($response, 'data.transaction_ref') ?: $request->reference),
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
            ->get($this->baseUrl($credentials).'/transaction/verify/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function isConfigured(GatewayCredentials $credentials): bool
    {
        return $credentials->has('secret_key');
    }

    public function webhookIsValid(GatewayCredentials $credentials, string $rawBody, ?string $signature): bool
    {
        $secretKey = $this->secretKey($credentials);

        return filled($secretKey)
            && filled($signature)
            && hash_equals(hash_hmac('sha512', $rawBody, $secretKey), (string) $signature);
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

    private function secretKey(GatewayCredentials $credentials): string
    {
        $secretKey = (string) $credentials->get('secret_key');

        if (Str::startsWith(Str::lower($secretKey), 'bearer ')) {
            return trim(substr($secretKey, 7), " \t\n\r\0\x0B\"'");
        }

        return $secretKey;
    }

    /**
     * Defaults to "test" (sandbox) so an account that's never touched this
     * setting keeps behaving exactly as it always has.
     */
    private function baseUrl(GatewayCredentials $credentials): string
    {
        $environment = $credentials->get('environment') ?: 'test';

        return $environment === 'live'
            ? rtrim((string) config('services.squad.live_base_url'), '/')
            : rtrim((string) config('services.squad.base_url'), '/');
    }

    private function amountInKobo(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
