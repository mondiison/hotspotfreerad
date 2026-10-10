<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'payment_gateway_settings',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if (blank($tenant->slug)) {
                $tenant->slug = static::uniqueSlug($tenant->company_name);
            }
        });

        static::updating(function (Tenant $tenant): void {
            if (blank($tenant->slug)) {
                $tenant->slug = static::uniqueSlug($tenant->company_name, $tenant->id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'is_active' => 'boolean',
            'require_two_factor' => 'boolean',
            'commission_rate' => 'decimal:2',
            'public_site_enabled' => 'boolean',
            'public_site_slides' => 'array',
            'payment_gateway_settings' => 'encrypted:array',
            'wallet_enabled' => 'boolean',
            'settlement_verified_at' => 'datetime',
            'subaccount_created_at' => 'datetime',
            'commission_subaccount_created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    public function billingSubscriptions(): HasMany
    {
        return $this->hasMany(TenantBillingSubscription::class);
    }

    public function platformBillingPayments(): HasMany
    {
        return $this->hasMany(PlatformBillingPayment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function currentBillingSubscription(): HasOne
    {
        return $this->hasOne(TenantBillingSubscription::class)->latestOfMany();
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function walletWithdrawals(): HasMany
    {
        return $this->hasMany(WalletWithdrawal::class);
    }

    public function publicUrl(): string
    {
        return route('tenant.public-site', $this);
    }

    public function paymentGatewaySettings(): array
    {
        return (array) ($this->payment_gateway_settings ?? []);
    }

    public function hasVerifiedSettlementAccount(): bool
    {
        return filled($this->settlement_account_number) && filled($this->settlement_verified_at);
    }

    /**
     * 2026-10-10: true once a super admin has both pointed this tenant's
     * wallet checkout at a specific gateway (Shop::paymentGateway()'s
     * override, independent of the platform's single global
     * active_gateway) AND successfully created a real subaccount on that
     * gateway -- the tenant's share of every future charge settles
     * straight to their own bank from then on, see
     * GatewayCredentialResolver::forPayment().
     */
    public function hasSubaccountSettlement(): bool
    {
        return filled($this->subaccount_settlement_gateway) && filled($this->subaccount_code);
    }

    /**
     * 2026-10-10: the MIRROR IMAGE of hasSubaccountSettlement() above --
     * that one is "platform is the main gateway account, tenant is a
     * subaccount on it" (wallet mode). This is "tenant is their own main
     * gateway account, PLATFORM is registered as a subaccount on IT" --
     * for a tenant using their own gateway credentials who still pays the
     * platform a commission (billing_model='commission'). See
     * TenantManagementService::createCommissionSubaccount().
     */
    public function hasCommissionSubaccount(): bool
    {
        return filled($this->commission_subaccount_gateway) && filled($this->commission_subaccount_code);
    }

    private static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $reserved = ['admin', 'api', 'hotspot', 'login', 'logout', 'storage', 'build'];
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $counter = 2;

        while (in_array($slug, $reserved, true) || static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
