<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_limit_bytes' => 'integer',
            'fup_data_threshold_bytes' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function supportsHotspot(): bool
    {
        return in_array($this->service_type ?: 'hotspot', ['hotspot', 'both'], true);
    }

    public function supportsPppoe(): bool
    {
        return in_array($this->service_type ?: 'hotspot', ['pppoe', 'both'], true);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
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
}
