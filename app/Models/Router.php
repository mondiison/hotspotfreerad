<?php

namespace App\Models;

use App\Support\WireGuardKeyPair;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Router extends Model
{
    /**
     * Fixed local RouterOS username the generated scripts provision for
     * read-only API access (monitoring, Wi-Fi scan, topology). Only the
     * password varies per router.
     */
    public const API_USERNAME = 'mmsradius-api';

    public const API_PORT = 8728;

    protected $guarded = [];

    protected $hidden = [
        'shared_secret',
        'wireguard_private_key',
        'api_password',
    ];

    protected function casts(): array
    {
        return [
            'shared_secret' => 'encrypted',
            'wireguard_private_key' => 'encrypted',
            'api_password' => 'encrypted',
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

            if (blank($router->api_password)) {
                $router->api_username = self::API_USERNAME;
                $router->api_password = Str::random(32);
                $router->api_port = self::API_PORT;
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
