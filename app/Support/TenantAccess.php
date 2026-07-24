<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Package;
use App\Models\PppoeSubscriber;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class TenantAccess
{
    public static function scopeShops(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->where('tenant_id', $user->tenant_id);
    }

    public static function scopeRouters(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $user->tenant_id));
    }

    public static function scopePackages(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $user->tenant_id));
    }

    public static function scopePayments(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $user->tenant_id));
    }

    public static function scopeExpenses(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->where('tenant_id', $user->tenant_id);
    }

    public static function scopeExpenseCategories(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->where(function (Builder $query) use ($user): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $user->tenant_id);
            });
    }

    public static function scopeSubscriptions(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $user->tenant_id));
    }

    public static function scopePppoeSubscribers(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $user->tenant_id));
    }

    public static function scopeVoucherBatches(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $user->tenant_id));
    }

    public static function scopeVouchers(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin()
            ? $query
            : $query->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $user->tenant_id));
    }

    public static function assertShop(Shop $shop, User $user): void
    {
        abort_unless($user->isSuperAdmin() || $shop->tenant_id === $user->tenant_id, 403);
    }

    public static function assertRouter(Router $router, User $user): void
    {
        $router->loadMissing('shop');

        self::assertShop($router->shop, $user);
    }

    public static function assertPackage(Package $package, User $user): void
    {
        $package->loadMissing('shop');

        self::assertShop($package->shop, $user);
    }

    public static function assertExpense(Expense $expense, User $user): void
    {
        abort_unless($user->isSuperAdmin() || $expense->tenant_id === $user->tenant_id, 403);
    }

    public static function assertSubscription(Subscription $subscription, User $user): void
    {
        $subscription->loadMissing('shop');

        self::assertShop($subscription->shop, $user);
    }

    public static function assertPppoeSubscriber(PppoeSubscriber $subscriber, User $user): void
    {
        $subscriber->loadMissing('shop');

        self::assertShop($subscriber->shop, $user);
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
        $rule = Rule::exists('shops', 'id');

        return $user->isSuperAdmin()
            ? $rule
            : $rule->where('tenant_id', $user->tenant_id);
    }
}
