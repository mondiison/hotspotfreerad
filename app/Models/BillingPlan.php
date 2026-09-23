<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
            'supports_wallet' => 'boolean',
            'wallet_commission_rate' => 'decimal:2',
        ];
    }

    public function tenantSubscriptions(): HasMany
    {
        return $this->hasMany(TenantBillingSubscription::class);
    }

    public function platformBillingPayments(): HasMany
    {
        return $this->hasMany(PlatformBillingPayment::class);
    }
}
