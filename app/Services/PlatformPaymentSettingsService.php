<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class PlatformPaymentSettingsService
{
    public const FLUTTERWAVE = 'payments.platform.flutterwave';

    public function rules(): array
    {
        return [
            'active_gateway' => ['required', 'string', Rule::in(array_keys(PaymentGatewayCatalog::onlineGateways()))],
            'client_id' => ['nullable', 'string', 'required_with:client_secret'],
            'client_secret' => ['nullable', 'string', 'required_with:client_id'],
            'webhook_secret_hash' => ['nullable', 'string'],
            'default_payment_method' => ['required', 'string', 'in:opay,card,bank_transfer'],
            'clear_client_credentials' => ['nullable', 'boolean'],
            'clear_webhook_secret' => ['nullable', 'boolean'],
        ];
    }

    public function update(array $data, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $settings = $this->stored();
        $settings['active_gateway'] = (string) ($data['active_gateway'] ?? $this->activeGateway());

        if ((bool) ($data['clear_client_credentials'] ?? false)) {
            unset($settings['client_id'], $settings['client_secret']);
        } elseif (filled($data['client_id'] ?? null) && filled($data['client_secret'] ?? null)) {
            $settings['client_id'] = $this->encrypt((string) $data['client_id']);
            $settings['client_secret'] = $this->encrypt((string) $data['client_secret']);
        }

        if ((bool) ($data['clear_webhook_secret'] ?? false)) {
            unset($settings['webhook_secret_hash']);
        } elseif (filled($data['webhook_secret_hash'] ?? null)) {
            $settings['webhook_secret_hash'] = $this->encrypt((string) $data['webhook_secret_hash']);
        }

        $settings['default_payment_method'] = (string) ($data['default_payment_method'] ?? $this->defaultPaymentMethod());

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::FLUTTERWAVE],
            ['value' => $settings]
        );

        Cache::forget($this->cacheKey());
    }

    public function snapshot(): array
    {
        return [
            'client_id_configured' => filled($this->clientId()),
            'client_secret_configured' => filled($this->clientSecret()),
            'webhook_secret_configured' => filled($this->webhookSecretHash()),
            'active_gateway' => $this->activeGateway(),
            'active_gateway_name' => PaymentGatewayCatalog::gatewayName($this->activeGateway()),
            'active_gateway_logo_url' => PaymentGatewayCatalog::gatewayLogoUrl($this->activeGateway()),
            'active_gateway_implemented' => $this->activeGatewayIsImplemented(),
            'active_gateway_details' => PaymentGatewayCatalog::gateway($this->activeGateway()),
            'gateway_options' => PaymentGatewayCatalog::gatewayOptions(),
            'default_payment_method' => $this->defaultPaymentMethod(),
            'source' => $this->hasStoredCredentials() ? 'database' : 'env',
        ];
    }

    public function clientId(): ?string
    {
        return $this->value('client_id', config('services.flutterwave.client_id'));
    }

    public function clientSecret(): ?string
    {
        return $this->value('client_secret', config('services.flutterwave.client_secret'));
    }

    public function webhookSecretHash(): ?string
    {
        return $this->value('webhook_secret_hash', config('services.flutterwave.webhook_secret_hash'));
    }

    public function defaultPaymentMethod(): string
    {
        $method = $this->stored()['default_payment_method'] ?? config('services.flutterwave.default_payment_method') ?? 'opay';

        return in_array($method, ['opay', 'card', 'bank_transfer'], true) ? (string) $method : 'opay';
    }

    public function activeGateway(): string
    {
        $gateway = $this->stored()['active_gateway'] ?? PaymentGatewayCatalog::FLUTTERWAVE;

        return array_key_exists($gateway, PaymentGatewayCatalog::onlineGateways()) ? (string) $gateway : PaymentGatewayCatalog::FLUTTERWAVE;
    }

    public function activeGatewayName(): string
    {
        return PaymentGatewayCatalog::gatewayName($this->activeGateway());
    }

    public function activeGatewayIsImplemented(): bool
    {
        return in_array($this->activeGateway(), PaymentGatewayCatalog::implementedGatewayKeys(), true);
    }

    public function hasStoredCredentials(): bool
    {
        $stored = $this->stored();

        return filled($stored['client_id'] ?? null) || filled($stored['client_secret'] ?? null);
    }

    private function value(string $key, mixed $fallback = null): ?string
    {
        $value = $this->stored()[$key] ?? null;

        if (blank($value)) {
            return filled($fallback) ? (string) $fallback : null;
        }

        return $this->decrypt((string) $value);
    }

    private function stored(): array
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(10), function (): array {
            $setting = PlatformSetting::query()->where('key', self::FLUTTERWAVE)->first();

            return is_array($setting?->value) ? $setting->value : [];
        });
    }

    private function cacheKey(): string
    {
        return 'platform-payment-settings:flutterwave';
    }

    private function encrypt(string $value): string
    {
        return Crypt::encryptString($value);
    }

    private function decrypt(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
