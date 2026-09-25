<?php

namespace App\Services;

use App\Models\PlatformBillingPayment;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Platform-scoped mirror of MonnifyService -- same API shape, but reading
 * credentials from PlatformPaymentSettingsService::gatewaySettings('monnify')
 * instead of a shop's own paymentGatewaySettings(), and charging a
 * PlatformBillingPayment (tenant paying HotspotFreeRAD) rather than a
 * hotspot customer's Payment.
 */
class PlatformMonnifyService
{
    public function __construct(private readonly PlatformPaymentSettingsService $settings) {}

    /**
     * @throws RequestException
     */
    public function initializeCheckout(PlatformBillingPayment $payment, string $redirectUrl): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($this->baseUrl().'/api/v1/merchant/transactions/init-transaction', [
                'amount' => (float) $payment->amount,
                'customerName' => $payment->tenant->company_name,
                'customerEmail' => $payment->tenant->owner_email,
                'paymentReference' => $payment->tx_ref,
                'paymentDescription' => $payment->billingPlan->name.' platform subscription',
                'currencyCode' => $payment->currency,
                'contractCode' => $this->contractCode(),
                'redirectUrl' => $redirectUrl,
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER'],
                'metadata' => [
                    'payment_type' => 'platform_subscription',
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                    'tenant_id' => $payment->tenant_id,
                    'tenant_name' => $payment->tenant->company_name,
                    'billing_plan_id' => $payment->billing_plan_id,
                    'billing_plan_name' => $payment->billingPlan->name,
                ],
            ])
            ->throw()
            ->json();

        return [
            'response' => $response,
            'provider_reference' => (string) (data_get($response, 'responseBody.transactionReference') ?: $payment->tx_ref),
            'checkout_url' => $this->checkoutUrl($response),
        ];
    }

    /**
     * @throws RequestException
     */
    public function verifyPayment(string $reference): array
    {
        $queryByPaymentReference = ! Str::startsWith($reference, 'MNFY|');

        $request = Http::withToken($this->accessToken())->acceptJson();

        if ($queryByPaymentReference) {
            return $request
                ->get($this->baseUrl().'/api/v2/merchant/transactions/query', [
                    'paymentReference' => $reference,
                ])
                ->throw()
                ->json();
        }

        return $request
            ->get($this->baseUrl().'/api/v2/transactions/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey())
            && filled($this->secretKey())
            && filled($this->contractCode());
    }

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
     * Monnify's disbursement account-validate endpoint -- not yet exercised
     * against a live account, matching this codebase's honesty pattern for
     * other freshly-added integrations.
     *
     * @return array{account_name: ?string}
     *
     * @throws RequestException
     */
    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->baseUrl().'/api/v1/disbursements/account/validate', [
                'accountNumber' => $accountNumber,
                'bankCode' => $bankCode,
            ])
            ->throw()
            ->json();

        return ['account_name' => data_get($response, 'responseBody.accountName')];
    }

    /**
     * Monnify's webhook signature and its checkout auth both use the same
     * secret_key -- there is no separate webhook secret field for this
     * gateway, unlike Flutterwave/Stripe.
     */
    public function webhookIsValid(string $rawBody, ?string $signature): bool
    {
        $secretKey = $this->secretKey();

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

    private function accessToken(): string
    {
        $apiKey = $this->apiKey();
        $secretKey = $this->secretKey();
        $baseUrl = $this->baseUrl();
        $cacheKey = 'monnify:platform-token:'.sha1($baseUrl.'|'.$apiKey.'|'.$secretKey);

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

    private function contractCode(): string
    {
        return (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::MONNIFY)['contract_code'] ?? '');
    }

    /**
     * Defaults to "test" (sandbox) so a platform that's never touched this
     * setting keeps behaving exactly as it always has, matching MonnifyService's
     * own default for the tenant-facing side.
     */
    private function baseUrl(): string
    {
        $environment = (string) ($this->settings->gatewaySettings(PaymentGatewayCatalog::MONNIFY)['environment'] ?? 'test');

        return $environment === 'live'
            ? rtrim((string) config('services.monnify.live_base_url'), '/')
            : rtrim((string) config('services.monnify.base_url'), '/');
    }
}
