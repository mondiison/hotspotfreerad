<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBillingSubscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function billingPlan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class);
    }
}
