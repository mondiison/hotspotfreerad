<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a bank account number to its real account holder name before a
 * tenant's settlement account is saved -- catches a typo'd account number or
 * the wrong account entirely before a real withdrawal payout goes to it.
 *
 * Uses whichever platform gateway the super admin already has credentials
 * saved for (Paystack, Monnify, or Flutterwave, in that priority order --
 * Paystack first since its resolve API is the simplest and most commonly
 * available), reusing PlatformFlutterwaveService/PlatformMonnifyService's
 * own credential lookup and access-token caching rather than duplicating it.
 * Paystack itself has no Platform*Service (it isn't a platformImplementedGatewayKeys()
 * billing/wallet gateway), so its two calls are made directly here with a
 * plain secret-key bearer token -- Paystack's resolve API needs no OAuth
 * token exchange, unlike Flutterwave v4 or Monnify.
 */
class BankAccountResolutionService
{
    private const PROVIDER_PRIORITY = ['paystack', 'monnify', 'flutterwave'];

    public function __construct(
        private readonly PlatformPaymentSettingsService $settings,
        private readonly PlatformFlutterwaveService $flutterwave,
        private readonly PlatformMonnifyService $monnify,
    ) {}

    public function activeProvider(): ?string
    {
        foreach (self::PROVIDER_PRIORITY as $gateway) {
            if ($this->settings->hasStoredCredentials($gateway)) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function banks(): array
    {
        $provider = $this->activeProvider();

        try {
            return match ($provider) {
                'paystack' => $this->paystackBanks(),
                'monnify' => $this->monnify->banks(),
                'flutterwave' => $this->flutterwave->banks(),
                default => [],
            };
        } catch (\Throwable $exception) {
            Log::warning('Fetching bank list failed', [
                'provider' => $provider,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'response_status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'response_body' => $exception instanceof RequestException ? $exception->response->body() : null,
            ]);

            return [];
        }
    }

    /**
     * @return array{account_name: ?string, error: ?string}
     */
    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        $provider = $this->activeProvider();

        if (! $provider) {
            return ['account_name' => null, 'error' => 'Bank account verification is not available yet -- ask a super admin to configure a platform payment gateway (Paystack, Monnify, or Flutterwave) first.'];
        }

        try {
            $result = match ($provider) {
                'paystack' => $this->paystackResolve($bankCode, $accountNumber),
                'monnify' => $this->monnify->resolveAccount($bankCode, $accountNumber),
                'flutterwave' => $this->flutterwave->resolveAccount($bankCode, $accountNumber),
            };
        } catch (\Throwable $exception) {
            Log::warning('Bank account resolution failed', [
                'provider' => $provider,
                'bank_code' => $bankCode,
                'account_number' => $accountNumber,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'response_status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'response_body' => $exception instanceof RequestException ? $exception->response->body() : null,
            ]);

            return ['account_name' => null, 'error' => 'Could not verify this account -- double-check the bank and account number and try again.'];
        }

        if (blank($result['account_name'] ?? null)) {
            return ['account_name' => null, 'error' => 'Could not resolve an account name for this bank and account number.'];
        }

        return ['account_name' => $result['account_name'], 'error' => null];
    }

    private function paystackSecretKey(): string
    {
        return (string) ($this->settings->gatewaySettings('paystack')['secret_key'] ?? '');
    }

    private function paystackBanks(): array
    {
        $response = Http::withToken($this->paystackSecretKey())
            ->acceptJson()
            ->get($this->paystackBaseUrl().'/bank', ['country' => 'nigeria', 'currency' => 'NGN'])
            ->throw()
            ->json();

        return collect(data_get($response, 'data', []))
            ->map(fn (array $bank): array => [
                'code' => (string) data_get($bank, 'code'),
                'name' => (string) data_get($bank, 'name'),
            ])
            ->all();
    }

    private function paystackResolve(string $bankCode, string $accountNumber): array
    {
        $response = Http::withToken($this->paystackSecretKey())
            ->acceptJson()
            ->get($this->paystackBaseUrl().'/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ])
            ->throw()
            ->json();

        return ['account_name' => data_get($response, 'data.account_name')];
    }

    private function paystackBaseUrl(): string
    {
        return rtrim((string) config('services.paystack.base_url'), '/');
    }
}
