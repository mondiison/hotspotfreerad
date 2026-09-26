<?php

namespace App\Services;

use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Platform-credentialed Monnify calls unrelated to checkout -- checkout
 * itself moved to the shared App\Services\Payments\Gateways\MonnifyGateway
 * (2026-09-26, used by both HotspotHostedCheckoutManager and
 * PlatformHostedCheckoutManager), which is why this class no longer has
 * initializeCheckout()/verifyPayment()/isConfigured()/webhookIsValid(). This
 * one survives purely for BankAccountResolutionService's banks()/
 * resolveAccount() calls, which have nothing to do with starting or
 * verifying a charge.
 */
class PlatformMonnifyService
{
    public function __construct(private readonly PlatformPaymentSettingsService $settings) {}

    /**
     * @return list<array{code: string, name: string}>
     *
     * @throws RequestException
     */
    public function banks(): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->baseUrl().'/api/v1/banks')
            ->throw()
            ->json();

        return collect(data_get($response, 'responseBody', []))
            ->map(fn (array $bank): array => [
                'code' => (string) data_get($bank, 'code'),
                'name' => (string) data_get($bank, 'name'),
            ])
            ->all();
    }

    /**
     * Monnify's v1 disbursement account-validate endpoint is deprecated --
     * confirmed live 2026-09-25 (`responseCode: "99"`, "This API endpoint has
     * been deprecated ... migrate to ... /api/v2/disbursements/account/validate"),
     * which is why this uses v2. Same accountNumber/bankCode query params and
     * bearer-token auth as v1 -- the deprecation notice didn't mention a
     * changed request shape, only the path -- but this hasn't been separately
     * confirmed against a real account yet, matching this codebase's honesty
     * pattern for other freshly-added integrations.
     *
     * @return array{account_name: ?string}
     *
     * @throws RequestException
     */
    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->baseUrl().'/api/v2/disbursements/account/validate', [
                'accountNumber' => $accountNumber,
                'bankCode' => $bankCode,
            ])
            ->throw()
            ->json();

        return ['account_name' => data_get($response, 'responseBody.accountName')];
    }

    private function accessToken(): string
    {
        $apiKey = $this->apiKey();
        $secretKey = $this->secretKey();
        $baseUrl = $this->baseUrl();
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

    private function apiKey(): string
    {
        return (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::MONNIFY)['public_key'] ?? '');
    }

    private function secretKey(): string
    {
        return (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::MONNIFY)['secret_key'] ?? '');
    }

    /**
     * Defaults to "test" (sandbox) so a platform that's never touched this
     * setting keeps behaving exactly as it always has.
     */
    private function baseUrl(): string
    {
        $environment = (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::MONNIFY)['environment'] ?? 'test');

        return $environment === 'live'
            ? rtrim((string) config('services.monnify.live_base_url'), '/')
            : rtrim((string) config('services.monnify.base_url'), '/');
    }
}
