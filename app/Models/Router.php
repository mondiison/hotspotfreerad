<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'shared_secret',
    ];

    protected function casts(): array
    {
        return [
            'shared_secret' => 'encrypted',
            'is_online' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
