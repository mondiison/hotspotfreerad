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

    /**
     * `tunnel_mode` also defaults to 'wireguard' at the DB level (the migration's
     * column default), but that alone only helps a row re-fetched from the
     * database -- it does nothing for a `new Router([...])` instance that's never
     * saved (used throughout this app's test suite to exercise script generation
     * without touching the DB) or for the in-memory model returned by create()
     * itself (Eloquent never re-fetches after INSERT). A model-level attribute
     * default covers both: it's applied before fill(), so an explicitly passed
     * tunnel_mode still overrides it normally.
     */
    protected $attributes = [
        'tunnel_mode' => 'wireguard',
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
            'zerotier_authorized_at' => 'datetime',
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

            // The `tunnel_mode` column defaults to 'wireguard' at the DB level too, but
            // that alone only helps a *freshly queried* model -- Eloquent never re-fetches
            // a row after INSERT, so an in-memory $router used immediately after create()
            // (the common case: RouterManagementService::create() passes the same instance
            // straight into script generation) would otherwise read tunnel_mode as null,
            // not 'wireguard', breaking every in_array($router->tunnel_mode, [...]) check
            // in MikroTikProvisioningService/RouterOsConnectionService. Set explicitly here
            // for the same reason the WireGuard keypair/API credentials are.
            if (blank($router->tunnel_mode)) {
                $router->tunnel_mode = 'wireguard';
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
