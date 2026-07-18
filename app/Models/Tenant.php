<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
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
            'public_site_enabled' => 'boolean',
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
