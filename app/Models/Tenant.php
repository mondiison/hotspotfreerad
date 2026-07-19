<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $guarded = [];

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

    public function publicUrl(): string
    {
        return route('tenant.public-site', $this);
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
