<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientBillingPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientBillingSubscription::class);
    }

    public function clientBillingPayments(): HasMany
    {
        return $this->hasMany(ClientBillingPayment::class);
    }
}
