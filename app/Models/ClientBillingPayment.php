<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientBillingPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientBillingPlan(): BelongsTo
    {
        return $this->belongsTo(ClientBillingPlan::class);
    }

    public function clientBillingSubscription(): BelongsTo
    {
        return $this->belongsTo(ClientBillingSubscription::class);
    }
}
