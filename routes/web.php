<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\RouterController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Hotspot\PortalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/hotspot/portal', [PortalController::class, 'show'])->name('hotspot.portal');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::resource('tenants', TenantController::class)->except('show');
    Route::resource('shops', ShopController::class)->except('show');
    Route::resource('routers', RouterController::class);
    Route::resource('packages', PackageController::class)->except('show');
});
