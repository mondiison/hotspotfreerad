<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function billingSubscriptions(): HasMany
    {
        return $this->hasMany(ClientBillingSubscription::class);
    }

    public function billingPayments(): HasMany
    {
        return $this->hasMany(ClientBillingPayment::class);
    }

    public function currentBillingSubscription(): HasOne
    {
        return $this->hasOne(ClientBillingSubscription::class)->latestOfMany();
    }

    /**
     * The single checkpoint User::canAccessRoute()'s `client` branch reads
     * -- a Client with no subscription at all (shouldn't normally happen,
     * every Client is created with one) is treated the same as a lapsed
     * one: deny by default, never implicitly allow.
     */
    public function hasUsableBillingAccess(): bool
    {
        $subscription = $this->currentBillingSubscription;

        return $subscription !== null && in_array($subscription->status, ['active', 'trialing'], true);
    }
}
