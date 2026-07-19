<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RouterController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\TenantBrandController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RedirectAfterLoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Hotspot\PortalController;
use App\Http\Controllers\TenantPublicSiteController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware('auth')->group(function () {
    Route::get('/redirect-after-login', RedirectAfterLoginController::class)->name('redirect-after-login');
    Route::get('/change-password', [ForcePasswordChangeController::class, 'edit'])->name('password.force-change');
    Route::put('/change-password', [ForcePasswordChangeController::class, 'update'])->name('password.force-update');
});

Route::get('/hotspot/portal', [PortalController::class, 'show'])->name('hotspot.portal');
Route::post('/hotspot/pay', [PortalController::class, 'pay'])->name('hotspot.pay');
Route::get('/hotspot/payment/callback', [PortalController::class, 'callback'])->name('hotspot.payment.callback');
Route::post('/hotspot/payment/webhook', [PortalController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('hotspot.payment.webhook');
Route::post('/hotspot/grant', [PortalController::class, 'grant'])->name('hotspot.grant');
Route::post('/billing/payment/webhook', [BillingController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('billing.payment.webhook');

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('billing/plans/create', [BillingController::class, 'createPlan'])->name('billing.plans.create');
    Route::post('billing/plans', [BillingController::class, 'storePlan'])->name('billing.plans.store');
    Route::get('billing/plans/{billingPlan}/edit', [BillingController::class, 'editPlan'])->name('billing.plans.edit');
    Route::put('billing/plans/{billingPlan}', [BillingController::class, 'updatePlan'])->name('billing.plans.update');
    Route::delete('billing/plans/{billingPlan}', [BillingController::class, 'destroyPlan'])->name('billing.plans.destroy');
    Route::post('billing/subscriptions', [BillingController::class, 'storeSubscription'])->name('billing.subscriptions.store');
    Route::post('billing/payments', [BillingController::class, 'checkout'])->name('billing.payments.checkout');
    Route::get('billing/payments/callback', [BillingController::class, 'callback'])->name('billing.payments.callback');
    Route::get('brand', [TenantBrandController::class, 'edit'])->name('brand.edit');
    Route::put('brand', [TenantBrandController::class, 'update'])->name('brand.update');
    Route::resource('users', UserController::class)->except('show');
    Route::post('tenants/{tenant}/owner-reset-link', [TenantController::class, 'sendOwnerResetLink'])->name('tenants.owner-reset-link');
    Route::resource('tenants', TenantController::class)->except('show');
    Route::resource('shops', ShopController::class)->except('show');
    Route::resource('routers', RouterController::class);
    Route::resource('packages', PackageController::class)->except('show');
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payment-settings', [PaymentSettingsController::class, 'index'])->name('payment-settings.index');
    Route::put('payment-settings/{shop}', [PaymentSettingsController::class, 'update'])->name('payment-settings.update');
});

Route::get('/{tenant:slug}', TenantPublicSiteController::class)->name('tenant.public-site');
