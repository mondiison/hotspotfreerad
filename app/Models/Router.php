<?php

namespace App\Models;

use App\Support\WireGuardKeyPair;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'shared_secret',
        'wireguard_private_key',
    ];

    protected function casts(): array
    {
        return [
            'shared_secret' => 'encrypted',
            'wireguard_private_key' => 'encrypted',
            'provisioning_settings' => 'array',
            'is_online' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Router $router): void {
            if (blank($router->wireguard_private_key)) {
                $keyPair = WireGuardKeyPair::generate();
                $router->wireguard_private_key = $keyPair['private'];
                $router->wireguard_public_key = $keyPair['public'];
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
