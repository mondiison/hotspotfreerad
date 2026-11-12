<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RouterProvisioningController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\VoucherBatchController;
use App\Http\Middleware\AuthorizeTenantStaff;
use Illuminate\Support\Facades\Route;

/*
 * The Flutter companion app's API surface -- Phase 1 (vouchers +
 * printing). AuthorizeTenantStaff is reused as-is from the web admin
 * (it's already generic over any route name, keyed through
 * StaffPermissions::forRoute()), so a staff member's access here is
 * governed by the exact same permission map the web sidebar already
 * enforces -- see StaffPermissions' own api.* entries.
 */
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

Route::middleware(['auth:sanctum', AuthorizeTenantStaff::class])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [MeController::class, 'show'])->name('api.me');
    Route::get('/shops', [ShopController::class, 'index'])->name('api.shops.index');

    Route::get('/voucher-batches', [VoucherBatchController::class, 'index'])->name('api.voucher-batches.index');
    Route::post('/voucher-batches', [VoucherBatchController::class, 'store'])->name('api.voucher-batches.store');
    Route::get('/voucher-batches/{voucherBatch}', [VoucherBatchController::class, 'show'])->name('api.voucher-batches.show');
    Route::post('/voucher-batches/{voucherBatch}/void-unused', [VoucherBatchController::class, 'voidUnused'])->name('api.voucher-batches.void-unused');
    Route::post('/vouchers/{voucher}/mark-sold', [VoucherBatchController::class, 'markVoucherSold'])->name('api.vouchers.mark-sold');

    // Reseller model Phase 3 (2026-11-14): zero-touch router provisioning.
    // Deliberately NOT added to StaffPermissions::ROUTE_PERMISSIONS -- that
    // omission is what makes these tenant_admin-only (super_admin too, both
    // bypass the map entirely); tenant_staff is denied by default for any
    // route missing from it, the same way admin.billing/admin.tenants are.
    Route::post('/router-provisioning', [RouterProvisioningController::class, 'store'])->name('api.router-provisioning.store');
    Route::post('/router-provisioning/{router}/mark-pushed', [RouterProvisioningController::class, 'markBootstrapPushed'])->name('api.router-provisioning.mark-pushed');
    Route::get('/router-provisioning/{router}/status', [RouterProvisioningController::class, 'status'])->name('api.router-provisioning.status');
});
