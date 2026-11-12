<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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

    /**
     * Phase 2 (2026-11-12) self-service additions -- a Client managing its
     * own shop's packages/vouchers. Mirrors TenantAccess's equivalent
     * shape, but PackageManagementService/VoucherManagementService are
     * hardcoded to TenantAccess internally (too permissive for a Client,
     * since their own tenant_id matches every shop in the whole tenant,
     * not just their own). These are called explicitly BEFORE delegating
     * to those shared services, so the narrower Client-scope check is the
     * real gate; the service's own TenantAccess check then passes
     * harmlessly (same tenant either way).
     */
    public static function scopePackages(Builder $query, User $user): Builder
    {
        return $query->whereHas('shop', fn (Builder $shop) => $shop->where('client_id', $user->client_id));
    }

    public static function scopeVoucherBatches(Builder $query, User $user): Builder
    {
        return $query->whereHas('shop', fn (Builder $shop) => $shop->where('client_id', $user->client_id));
    }

    public static function scopeVouchers(Builder $query, User $user): Builder
    {
        return $query->whereHas('shop', fn (Builder $shop) => $shop->where('client_id', $user->client_id));
    }

    public static function scopePayments(Builder $query, User $user): Builder
    {
        return $query->whereHas('shop', fn (Builder $shop) => $shop->where('client_id', $user->client_id));
    }

    public static function scopeSubscriptions(Builder $query, User $user): Builder
    {
        return $query->whereHas('shop', fn (Builder $shop) => $shop->where('client_id', $user->client_id));
    }

    public static function assertPackage(Package $package, User $user): void
    {
        $package->loadMissing('shop');

        self::assertShop($package->shop, $user);
    }

    public static function assertVoucherBatch(VoucherBatch $batch, User $user): void
    {
        $batch->loadMissing('shop');

        self::assertShop($batch->shop, $user);
    }

    public static function assertVoucher(Voucher $voucher, User $user): void
    {
        $voucher->loadMissing('shop');

        self::assertShop($voucher->shop, $user);
    }

    public static function shopExistsRule(User $user): Exists
    {
        return Rule::exists('shops', 'id')->where('client_id', $user->client_id);
    }
}
