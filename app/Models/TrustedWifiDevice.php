<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustedWifiDevice extends Model
{
    public const NETWORK_STAFF = 'staff';

    public const NETWORK_MGMT = 'mgmt';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_provisioned_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isCurrentlyActive(): bool
    {
        return $this->is_active && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
