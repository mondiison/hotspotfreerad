<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PppoeSubscriber extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_provisioned_at' => 'datetime',
            'reminder_3day_sent_at' => 'datetime',
            'reminder_1day_sent_at' => 'datetime',
            'reminder_expired_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PppoeSubscriber $subscriber): void {
            // Backs the "pay to renew" email link (PppoeRenewalController) --
            // same short, random, unguessable token precedent
            // Router::public_token already set for the self-service connect
            // link, rather than exposing the username or numeric id directly.
            if (blank($subscriber->public_token)) {
                $subscriber->public_token = Str::random(22);
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isCurrentlyActive(): bool
    {
        return $this->is_active && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
