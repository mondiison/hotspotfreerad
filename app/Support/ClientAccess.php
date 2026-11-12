<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Router;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sibling of TenantAccess, not a change to it -- every existing TenantAccess
 * method keeps scoping "this tenant's own shops" exactly as it always has
 * (a shop owned by a Client is still that tenant's shop too, tenant_id is
 * untouched). This class answers the narrower question a 'client'-role user
 * needs: "my own Client's shops only," via shops.client_id rather than
 * shops.tenant_id. Client-facing controllers call this; everything else in
 * the app keeps calling TenantAccess unchanged.
 */
class ClientAccess
{
    public static function scopeShops(Builder $query, User $user): Builder
    {
        return $query->where('client_id', $user->client_id);
    }

    public static function assertShop(Shop $shop, User $user): void
    {
        abort_unless($user->isClient() && $shop->client_id === $user->client_id, 403);
    }

    public static function assertRouter(Router $router, User $user): void
    {
        $router->loadMissing('shop');

        self::assertShop($router->shop, $user);
    }

    public static function assertClient(Client $client, User $user): void
    {
        abort_unless($user->isClient() && $client->id === $user->client_id, 403);
    }
}
