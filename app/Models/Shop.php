<?php

namespace App\Models;

use App\Services\PlatformPaymentSettingsService;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'flutterwave_client_id',
        'flutterwave_client_secret',
        'flutterwave_secret_key',
        'flutterwave_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'flutterwave_client_id' => 'encrypted',
            'flutterwave_client_secret' => 'encrypted',
            'flutterwave_secret_key' => 'encrypted',
            'flutterwave_webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'allow_test_access' => 'boolean',
            'auto_recover_mac_changes' => 'boolean',
            'trial_enabled' => 'boolean',
            'trial_duration_minutes' => 'integer',
            'trial_max_uses_per_day' => 'integer',
        ];
    }

    /**
     * 2026-10-10: a wallet-enabled tenant normally follows the platform's
     * one shared active gateway (walletGateway()) -- but
     * subaccount_settlement_gateway lets a super admin pin a SPECIFIC
     * tenant to a different gateway instead, independent of whatever the
     * platform's global setting is. This is what makes it safe to pilot
     * automated subaccount settlement on one test tenant (pointed at
     * Paystack) while every other wallet tenant -- including a live one
     * already running on the platform's actual active gateway -- keeps
     * resolving exactly as before, since this override is null for them.
     */
    public function paymentGateway(): string
    {
        if ($this->tenant?->wallet_enabled) {
            return $this->tenant->subaccount_settlement_gateway
                ?: app(PlatformPaymentSettingsService::class)->walletGateway();
        }

        return $this->payment_gateway ?: PaymentGatewayCatalog::FLUTTERWAVE;
    }

    public function paymentGatewayName(): string
    {
        return PaymentGatewayCatalog::gatewayName($this->paymentGateway());
    }

    public function paymentGatewayIsImplemented(): bool
    {
        return in_array($this->paymentGateway(), PaymentGatewayCatalog::implementedGatewayKeys(), true);
    }

    public function paymentGatewayLogoUrl(): string
    {
        return PaymentGatewayCatalog::gatewayLogoUrl($this->paymentGateway());
    }

    public function paymentGatewaySettings(): array
    {
        return $this->tenant?->paymentGatewaySettings() ?? [];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function routers(): HasMany
    {
        return $this->hasMany(Router::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function pppoeSubscribers(): HasMany
    {
        return $this->hasMany(PppoeSubscriber::class);
    }

    public function posDevices(): HasMany
    {
        return $this->hasMany(PosDevice::class);
    }

    public function voucherBatches(): HasMany
    {
        return $this->hasMany(VoucherBatch::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function trialPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'trial_package_id');
    }

    public function trialRedemptions(): HasMany
    {
        return $this->hasMany(TrialRedemption::class);
    }

    /**
     * The free trial (2026-09-27, an admin-configured promotional feature,
     * deliberately separate from the per-package "Start test access"
     * debugging button/allow_test_access above) reuses the exact same
     * RadiusProvisioningService::grantSubscriptionAccess() path every real
     * paid package uses -- which needs a real Package row to read
     * limit_uptime_seconds/speed_limit_profile from and to derive a RADIUS
     * group. Rather than teach RADIUS provisioning a second, package-less
     * code path, each shop gets one hidden (is_active=false, so it never
     * appears in the customer-facing package grid) auto-managed Package
     * that always mirrors this shop's current trial_duration_minutes/
     * trial_speed_limit_profile -- created once, then kept in sync on every
     * call so an admin's settings change takes effect on the very next
     * trial grant with no separate sync step required.
     */
    public function ensureTrialPackage(): Package
    {
        $package = $this->trial_package_id ? $this->trialPackage()->first() : null;

        $attributes = [
            'limit_uptime_seconds' => max(60, (int) $this->trial_duration_minutes * 60),
            'speed_limit_profile' => (string) ($this->trial_speed_limit_profile ?: '1M/1M'),
        ];

        if ($package) {
            $package->forceFill($attributes)->save();

            return $package;
        }

        $package = Package::create($attributes + [
            'shop_id' => $this->id,
            'name' => 'Free Trial',
            'service_type' => 'hotspot',
            'price' => 0,
            'currency' => 'NGN',
            'is_active' => false,
            'is_system' => true,
        ]);

        $this->forceFill(['trial_package_id' => $package->id])->save();

        return $package;
    }

    public function trialUsesToday(string $macAddress): int
    {
        return $this->trialRedemptions()
            ->where('mac_address', $macAddress)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    public function trialUsesRemainingToday(string $macAddress): int
    {
        return max(0, (int) $this->trial_max_uses_per_day - $this->trialUsesToday($macAddress));
    }

    /**
     * A tenant with several shops may staff each one with a different
     * attendant/contact -- this shop's own contact_phone overrides the
     * tenant-wide default (Tenant::contact_phone) when set, falling back to
     * it otherwise so a shop that's never set its own still shows something.
     */
    public function contactPhone(): ?string
    {
        return $this->contact_phone ?: $this->tenant?->contact_phone;
    }

    public function hasCompleteFlutterwaveCredentials(): bool
    {
        return filled($this->flutterwave_client_id) && filled($this->flutterwave_client_secret);
    }

    public function hasFlutterwaveHostedCheckoutKey(): bool
    {
        return filled($this->flutterwave_secret_key);
    }

    public function hasFlutterwaveWebhookSecret(): bool
    {
        return filled($this->flutterwave_webhook_secret);
    }
}
