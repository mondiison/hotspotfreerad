<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\RouterController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Hotspot\PortalController;
use App\Http\Controllers\TenantPublicSiteController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/{tenant:slug}/login', [LoginController::class, 'create'])->name('tenant.login');
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/hotspot/portal', [PortalController::class, 'show'])->name('hotspot.portal');
Route::post('/hotspot/pay', [PortalController::class, 'pay'])->name('hotspot.pay');
Route::get('/hotspot/payment/callback', [PortalController::class, 'callback'])->name('hotspot.payment.callback');
Route::post('/hotspot/payment/webhook', [PortalController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('hotspot.payment.webhook');
Route::post('/hotspot/grant', [PortalController::class, 'grant'])->name('hotspot.grant');

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('billing/plans/create', [BillingController::class, 'createPlan'])->name('billing.plans.create');
    Route::post('billing/plans', [BillingController::class, 'storePlan'])->name('billing.plans.store');
    Route::get('billing/plans/{billingPlan}/edit', [BillingController::class, 'editPlan'])->name('billing.plans.edit');
    Route::put('billing/plans/{billingPlan}', [BillingController::class, 'updatePlan'])->name('billing.plans.update');
    Route::delete('billing/plans/{billingPlan}', [BillingController::class, 'destroyPlan'])->name('billing.plans.destroy');
    Route::post('billing/subscriptions', [BillingController::class, 'storeSubscription'])->name('billing.subscriptions.store');
    Route::post('billing/payments', [BillingController::class, 'checkout'])->name('billing.payments.checkout');
    Route::get('billing/payments/callback', [BillingController::class, 'callback'])->name('billing.payments.callback');
    Route::resource('tenants', TenantController::class)->except('show');
    Route::resource('shops', ShopController::class)->except('show');
    Route::resource('routers', RouterController::class);
    Route::resource('packages', PackageController::class)->except('show');
});

Route::get('/{tenant:slug}', TenantPublicSiteController::class)->name('tenant.public-site');
