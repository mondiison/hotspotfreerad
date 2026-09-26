<?php

namespace App\Services;

use App\Jobs\SyncRouterWalledGarden;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\User;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/**
 * Stores platform billing's own gateway credentials -- separate from every
 * tenant/shop's own gateway_settings (PaymentSettingsService), this is the
 * platform's own account used for tenants paying their HotspotFreeRAD
 * subscription (PlatformFlutterwaveService/PlatformStripeService/
 * PlatformMonnifyService).
 *
 * One PlatformSetting row per gateway (`payments.platform.gateway.{key}`),
 * each field individually encrypted -- unlike the tenant-facing
 * PaymentSettingsService, which stores gateway_settings as plaintext JSON,
 * these are the platform's own master credentials, a meaningfully higher-
 * value target, so every field stays encrypted at rest regardless of
 * gateway. A separate `payments.platform.general` row holds the
 * gateway-independent active_gateway/default_payment_method choice, so
 * switching the active gateway never touches another gateway's stored
 * credentials -- each gateway keeps its own settings even while inactive,
 * matching the "leave blank to keep saved value" promise the tenant-facing
 * card already makes.
 */
class PlatformPaymentSettingsService
{
    private const GENERAL_KEY = 'payments.platform.general';

    private const GATEWAY_KEY_PREFIX = 'payments.platform.gateway.';

    public function rules(): array
    {
        return [
            'active_gateway' => ['required', 'string', Rule::in(array_keys(PaymentGatewayCatalog::onlineGateways()))],
            'gateway_settings' => ['nullable', 'array'],
            'gateway_settings.*' => ['nullable', 'string', 'max:1000'],
            'gateway_settings.environment' => ['nullable', Rule::in(['live', 'test'])],
            'default_payment_method' => ['nullable', 'string', 'in:opay,card,bank_transfer'],
            'clear_gateway_credentials' => ['nullable', 'boolean'],
        ];
    }

    public function update(array $data, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $previousWalletGateway = $this->walletGateway();

        $gateway = (string) ($data['active_gateway'] ?? $this->activeGateway());

        $general = $this->storedGeneral();
        $general['active_gateway'] = $gateway;

        if ($gateway === PaymentGatewayCatalog::FLUTTERWAVE) {
            $general['default_payment_method'] = (string) ($data['default_payment_method'] ?? $this->defaultPaymentMethod());
        }

        PlatformSetting::query()->updateOrCreate(['key' => self::GENERAL_KEY], ['value' => $general]);

        if ((bool) ($data['clear_gateway_credentials'] ?? false)) {
            PlatformSetting::query()->where('key', $this->gatewayKey($gateway))->delete();
        } else {
            $incoming = $this->cleanGatewaySettings($gateway, (array) ($data['gateway_settings'] ?? []));

            if ($incoming !== []) {
                $existing = $this->storedGatewayRaw($gateway);
                $encrypted = collect($incoming)->mapWithKeys(fn (string $value, string $key): array => [$key => $this->encrypt($value)])->all();

                // Merge into (not replace) -- a field left blank means "keep the
                // saved value" per the form's own placeholder text, same promise
                // PaymentSettingsService::updates() already makes for tenants.
                PlatformSetting::query()->updateOrCreate(
                    ['key' => $this->gatewayKey($gateway)],
                    ['value' => array_merge($existing, $encrypted)]
                );
            }
        }

        Cache::forget($this->cacheKey(self::GENERAL_KEY));
        Cache::forget($this->cacheKey($this->gatewayKey($gateway)));

        // Every wallet-enabled tenant's shops resolve their checkout gateway from
        // walletGateway() (see Shop::paymentGateway()), not from anything saved on
        // the shop itself -- so a change here can silently break checkout for every
        // one of them at once unless their routers' walled gardens are re-synced too,
        // the same "gateway changed" trigger PaymentSettingsService::update() already
        // uses for a tenant's own gateway switch. Compares the *effective* wallet
        // gateway (walletGateway()'s Flutterwave-fallback-aware result), not the raw
        // active_gateway, so picking a not-yet-wallet-capable gateway like Paystack
        // -- which silently keeps every wallet tenant on Flutterwave under the hood
        // -- doesn't dispatch a pointless resync.
        if ($this->walletGateway() !== $previousWalletGateway) {
            Router::whereHas('shop.tenant', fn ($query) => $query->where('wallet_enabled', true))
                ->pluck('id')
                ->each(fn (int $routerId) => SyncRouterWalledGarden::dispatch($routerId));
        }
    }

    public function snapshot(): array
    {
        $gateway = $this->activeGateway();

        return [
            'active_gateway' => $gateway,
            'active_gateway_name' => PaymentGatewayCatalog::gatewayName($gateway),
            'active_gateway_logo_url' => PaymentGatewayCatalog::gatewayLogoUrl($gateway),
            'active_gateway_implemented' => $this->activeGatewayIsImplemented(),
            'active_gateway_details' => PaymentGatewayCatalog::gateway($gateway),
            'gateway_options' => PaymentGatewayCatalog::gatewayOptions(),
            'default_payment_method' => $this->defaultPaymentMethod(),
            'source' => $this->hasStoredCredentials($gateway) ? 'database' : 'env',
        ];
    }

    /**
     * Decrypted settings for one gateway (defaults to the active one).
     * Flutterwave alone also falls back to legacy `.env` config for its
     * three original fields, matching this class's pre-multi-gateway
     * behavior so an install that's only ever used `.env` isn't affected.
     *
     * @return array<string,string>
     */
    public function gatewaySettings(?string $gateway = null): array
    {
        $gateway = $gateway ?: $this->activeGateway();

        $decrypted = collect($this->storedGatewayRaw($gateway))
            ->mapWithKeys(fn (string $value, string $key): array => [$key => $this->decrypt($value)])
            ->all();

        if ($gateway === PaymentGatewayCatalog::FLUTTERWAVE) {
            $decrypted['client_id'] = $decrypted['client_id'] ?? (string) config('services.flutterwave.client_id');
            $decrypted['client_secret'] = $decrypted['client_secret'] ?? (string) config('services.flutterwave.client_secret');
            $decrypted['webhook_secret'] = $decrypted['webhook_secret'] ?? (string) config('services.flutterwave.webhook_secret_hash');
        }

        return array_filter($decrypted, fn (string $value): bool => filled($value));
    }

    public function gatewayCredential(string $gateway, string $field): ?string
    {
        return $this->gatewaySettings($gateway)[$field] ?? null;
    }

    /**
     * @deprecated Kept only so PlatformFlutterwaveService's existing call
     * sites need no changes -- reads the same data gatewaySettings('flutterwave')
     * now returns.
     */
    public function clientId(): ?string
    {
        return $this->gatewayCredential(PaymentGatewayCatalog::FLUTTERWAVE, 'client_id');
    }

    public function clientSecret(): ?string
    {
        return $this->gatewayCredential(PaymentGatewayCatalog::FLUTTERWAVE, 'client_secret');
    }

    public function webhookSecretHash(): ?string
    {
        return $this->gatewayCredential(PaymentGatewayCatalog::FLUTTERWAVE, 'webhook_secret');
    }

    public function flutterwaveHostedCheckoutSecretKey(): ?string
    {
        return $this->gatewayCredential(PaymentGatewayCatalog::FLUTTERWAVE, 'secret_key');
    }

    public function defaultPaymentMethod(): string
    {
        $method = $this->storedGeneral()['default_payment_method'] ?? config('services.flutterwave.default_payment_method') ?? 'opay';

        return in_array($method, ['opay', 'card', 'bank_transfer'], true) ? (string) $method : 'opay';
    }

    public function activeGateway(): string
    {
        $gateway = $this->storedGeneral()['active_gateway'] ?? PaymentGatewayCatalog::FLUTTERWAVE;

        return array_key_exists($gateway, PaymentGatewayCatalog::onlineGateways()) ? (string) $gateway : PaymentGatewayCatalog::FLUTTERWAVE;
    }

    public function activeGatewayName(): string
    {
        return PaymentGatewayCatalog::gatewayName($this->activeGateway());
    }

    /**
     * A gateway being "live" for tenant hotspot checkout doesn't mean
     * platform billing has an adapter for it too -- see
     * PaymentGatewayCatalog::platformImplementedGatewayKeys().
     */
    public function activeGatewayIsImplemented(): bool
    {
        return in_array($this->activeGateway(), PaymentGatewayCatalog::platformImplementedGatewayKeys(), true);
    }

    /**
     * The gateway a wallet-enabled tenant's customer payments actually route
     * through -- follows the platform's own "Active gateway" choice, but only
     * among gateways with a tenant-facing service that knows how to use
     * platform-owned credentials (PaymentGatewayCatalog::walletCapableGatewayKeys(),
     * a deliberately narrower list than activeGatewayIsImplemented()'s -- Paystack
     * gained a real platform-billing adapter without also gaining wallet-credential
     * support on the tenant-facing PaystackService, so it's excluded here even
     * though it's "implemented" for billing). The "Active gateway" dropdown still
     * lets an admin pick Paystack/Squad for planning purposes, but following that
     * choice here would silently break every wallet-enabled tenant's live customer
     * checkout the moment it's selected -- falling back to Flutterwave instead
     * keeps wallet mode on its previous, proven-working default until a gateway
     * gains the same wallet-credential support FlutterwaveService/MonnifyService/
     * StripeService already have.
     */
    public function walletGateway(): string
    {
        return in_array($this->activeGateway(), PaymentGatewayCatalog::walletCapableGatewayKeys(), true)
            ? $this->activeGateway()
            : PaymentGatewayCatalog::FLUTTERWAVE;
    }

    public function hasStoredCredentials(?string $gateway = null): bool
    {
        return $this->storedGatewayRaw($gateway ?: $this->activeGateway()) !== [];
    }

    private function cleanGatewaySettings(string $gateway, array $settings): array
    {
        $allowedFields = array_keys(PaymentGatewayCatalog::platformCredentialFields($gateway));

        return collect($settings)
            ->only($allowedFields)
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->all();
    }

    private function storedGeneral(): array
    {
        return Cache::remember($this->cacheKey(self::GENERAL_KEY), now()->addMinutes(10), function (): array {
            $setting = PlatformSetting::query()->where('key', self::GENERAL_KEY)->first();

            return is_array($setting?->value) ? $setting->value : [];
        });
    }

    private function storedGatewayRaw(string $gateway): array
    {
        return Cache::remember($this->cacheKey($this->gatewayKey($gateway)), now()->addMinutes(10), function () use ($gateway): array {
            $setting = PlatformSetting::query()->where('key', $this->gatewayKey($gateway))->first();

            return is_array($setting?->value) ? $setting->value : [];
        });
    }

    private function gatewayKey(string $gateway): string
    {
        return self::GATEWAY_KEY_PREFIX.$gateway;
    }

    private function cacheKey(string $settingKey): string
    {
        return 'platform-payment-settings:'.$settingKey;
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
