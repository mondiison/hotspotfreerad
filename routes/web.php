<?php

use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\ClientBillingPlanController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\CustomerContactController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocsController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\PosDeviceController;
use App\Http\Controllers\Admin\PppoeSubscriberController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RouterController;
use App\Http\Controllers\Admin\SalesReportController;
use App\Http\Controllers\Admin\SecurityActivityController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SetupCenterController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\StandalonePppoeGeneratorController;
use App\Http\Controllers\Admin\StandalonePtpGeneratorController;
use App\Http\Controllers\Admin\StandaloneScriptGeneratorController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\TenantBrandController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TopologyController;
use App\Http\Controllers\Admin\TrustedWifiDeviceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\WalletController;
use App\Http\Controllers\Admin\WalletWithdrawalController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RedirectAfterLoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Client\BillingController as ClientBillingController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\RouterController as ClientRouterController;
use App\Http\Controllers\Hotspot\PortalController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\PppoeRenewalController;
use App\Http\Controllers\TenantPublicSiteController;
use App\Http\Middleware\AuthorizeClient;
use App\Http\Middleware\AuthorizeTenantStaff;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', MarketingController::class)->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store']);
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
Route::get('/hotspot/go/{token}', [PortalController::class, 'connect'])->name('hotspot.connect');
Route::get('/hotspot/login-page', [PortalController::class, 'loginPage'])->name('hotspot.login-page');
Route::post('/hotspot/pay', [PortalController::class, 'pay'])->name('hotspot.pay');
Route::post('/hotspot/payment/bank-transfer/check', [PortalController::class, 'checkBankTransfer'])->name('hotspot.payment.bank-transfer.check');
Route::post('/hotspot/payment/verify', [PortalController::class, 'verify'])->name('hotspot.payment.verify');
Route::get('/hotspot/payment/callback', [PortalController::class, 'callback'])->name('hotspot.payment.callback');
Route::post('/hotspot/payment/webhook', [PortalController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('hotspot.payment.webhook');
Route::post('/hotspot/grant', [PortalController::class, 'grant'])->name('hotspot.grant');
Route::post('/hotspot/trial/start', [PortalController::class, 'startTrial'])->name('hotspot.trial.start');
Route::post('/hotspot/voucher/redeem', [PortalController::class, 'redeemVoucher'])->name('hotspot.voucher.redeem');

// 2026-11-10: the "pay to renew" email link -- deliberately NOT nested
// under /hotspot, since PPPoE has no captive-portal/router-redirect
// concept at all (see PppoeRenewalController's own docblock). Reuses the
// existing /hotspot/payment/callback|verify|webhook routes for gateway
// confirmation rather than duplicating them.
Route::get('/pppoe/renew/{token}', [PppoeRenewalController::class, 'show'])->name('pppoe.renewal.show');
Route::post('/pppoe/renew/{token}', [PppoeRenewalController::class, 'pay'])->name('pppoe.renewal.pay');
Route::post('/billing/payment/webhook', [BillingController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('billing.payment.webhook');

// 2026-11-12: a Client paying its Tenant -- the gateway POSTs here directly,
// so this sits outside both the 'auth' and AuthorizeClient-gated client.*
// group below, same shape as hotspot/billing's own webhook routes above.
Route::post('/client/billing/webhook', [ClientBillingController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('client.billing.webhook');

// The reseller model's Client tier (2026-11-12): a wholly separate route
// group/middleware from admin.* -- a 'client'-role user is denied admin.*
// entirely by the existing StaffPermissions fallback (hasStaffPermission()
// is strictly tied to isTenantStaff()), so no change was needed there.
// AuthorizeClient is the single checkpoint for the lapse-enforcement deny
// (see hotspot:enforce-client-billing) -- every route below it is denied
// outright the moment a Client's subscription is no longer active/trialing.
Route::middleware(['auth', AuthorizeClient::class])->prefix('client')->name('client.')->group(function () {
    Route::get('/', [ClientDashboardController::class, 'index'])->name('dashboard');
    Route::get('billing', [ClientBillingController::class, 'index'])->name('billing.index');
    Route::post('billing/checkout', [ClientBillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('billing/callback', [ClientBillingController::class, 'callback'])->name('billing.callback');
    Route::get('routers/{router}', [ClientRouterController::class, 'show'])->name('routers.show');
});

Route::middleware(['auth', AuthorizeTenantStaff::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::view('settings', 'admin.settings.index')->name('settings.index');
    Route::get('setup', SetupCenterController::class)->name('setup.index');
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::view('passkeys', 'admin.passkeys.index')->name('passkeys.index');
    Route::get('security-activity-export', [SecurityActivityController::class, 'export'])->name('security-activity.export');
    Route::view('security-activity', 'admin.security-activity.index')->name('security-activity.index');
    Route::get('security', SecurityController::class)->name('security.index');
    Route::get('tools/script-generator', StandaloneScriptGeneratorController::class)->name('tools.script-generator');
    Route::get('tools/ptp-generator', StandalonePtpGeneratorController::class)->name('tools.ptp-generator');
    Route::get('tools/pppoe-generator', StandalonePppoeGeneratorController::class)->name('tools.pppoe-generator');
    Route::get('docs', DocsController::class)->name('docs.index');
    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('billing/plans/create', [BillingController::class, 'createPlan'])->name('billing.plans.create');
    Route::post('billing/plans', [BillingController::class, 'storePlan'])->name('billing.plans.store');
    Route::get('billing/plans/{billingPlan}/edit', [BillingController::class, 'editPlan'])->name('billing.plans.edit');
    Route::put('billing/plans/{billingPlan}', [BillingController::class, 'updatePlan'])->name('billing.plans.update');
    Route::delete('billing/plans/{billingPlan}', [BillingController::class, 'destroyPlan'])->name('billing.plans.destroy');
    Route::post('billing/subscriptions', [BillingController::class, 'storeSubscription'])->name('billing.subscriptions.store');
    Route::post('billing/payments', [BillingController::class, 'checkout'])->name('billing.payments.checkout');
    Route::post('billing/payments/{payment}/verify', [BillingController::class, 'verify'])->name('billing.payments.verify');
    Route::get('billing/payments/callback', [BillingController::class, 'callback'])->name('billing.payments.callback');
    Route::get('brand', [TenantBrandController::class, 'edit'])->name('brand.edit');
    Route::put('brand', [TenantBrandController::class, 'update'])->name('brand.update');
    Route::resource('users', UserController::class)->except('show');
    Route::post('tenants/{tenant}/owner-reset-link', [TenantController::class, 'sendOwnerResetLink'])->name('tenants.owner-reset-link');
    Route::resource('tenants', TenantController::class)->except('show');
    Route::resource('shops', ShopController::class)->except('show');
    // The reseller model's Client tier (2026-11-12) -- tenant_admin's own
    // CRUD for onboarding Clients and defining the plans sold to them. Not
    // added to StaffPermissions' route map, so tenant_staff is denied by
    // default (consistent with everything else not deliberately opened up
    // to staff yet).
    Route::resource('clients', ClientController::class)->except('show');
    Route::post('clients/reselling/enable', [ClientController::class, 'enableReselling'])->name('clients.reselling.enable');
    Route::post('clients/reselling/disable', [ClientController::class, 'disableReselling'])->name('clients.reselling.disable');
    Route::post('clients/checkout-gateway', [ClientController::class, 'saveCheckoutGateway'])->name('clients.checkout-gateway');
    Route::resource('client-billing-plans', ClientBillingPlanController::class)->except('show');
    Route::post('routers/{router}/regenerate-wireguard-key', [RouterController::class, 'regenerateWireguardKey'])->name('routers.regenerate-wireguard-key');
    Route::post('routers/{router}/regenerate-api-credentials', [RouterController::class, 'regenerateApiCredentials'])->name('routers.regenerate-api-credentials');
    Route::post('routers/{router}/test-api-connection', [RouterController::class, 'testApiConnection'])->name('routers.test-api-connection');
    Route::post('routers/{router}/provision-hotspot', [RouterController::class, 'provisionHotspot'])->name('routers.provision-hotspot');
    Route::post('routers/{router}/provision-pppoe', [RouterController::class, 'provisionPppoe'])->name('routers.provision-pppoe');
    Route::post('routers/{router}/provision-pos', [RouterController::class, 'provisionPos'])->name('routers.provision-pos');
    Route::post('routers/{router}/provision-staff-wifi', [RouterController::class, 'provisionStaffWifi'])->name('routers.provision-staff-wifi');
    Route::post('routers/{router}/provision-lan', [RouterController::class, 'provisionLan'])->name('routers.provision-lan');
    Route::post('routers/{router}/push-fresh-infrastructure', [RouterController::class, 'pushFreshInfrastructure'])->name('routers.push-fresh-infrastructure');
    Route::post('routers/{router}/sync-trunk-ports', [RouterController::class, 'syncTrunkPorts'])->name('routers.sync-trunk-ports');
    Route::get('topology', [TopologyController::class, 'index'])->name('topology.index');
    Route::resource('routers', RouterController::class);
    Route::resource('packages', PackageController::class)->except('show');
    Route::get('subscriptions-export', [SubscriptionController::class, 'export'])->name('subscriptions.export');
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('vouchers-export', [VoucherController::class, 'export'])->name('vouchers.export');
    Route::get('vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::get('voucher-batches/{voucherBatch}/export', [VoucherController::class, 'exportBatch'])->name('voucher-batches.export');
    Route::get('voucher-batches/{voucherBatch}/print', [VoucherController::class, 'print'])->name('voucher-batches.print');
    Route::get('pos-devices', [PosDeviceController::class, 'index'])->name('pos-devices.index');
    Route::get('trusted-wifi-devices', [TrustedWifiDeviceController::class, 'index'])->name('trusted-wifi-devices.index');
    Route::get('customer-contacts-export', [CustomerContactController::class, 'export'])->name('customer-contacts.export');
    Route::get('customer-contacts', [CustomerContactController::class, 'index'])->name('customer-contacts.index');
    Route::get('pppoe-subscribers-export', [PppoeSubscriberController::class, 'export'])->name('pppoe-subscribers.export');
    Route::get('pppoe-subscribers', [PppoeSubscriberController::class, 'index'])->name('pppoe-subscribers.index');
    Route::get('payments-export', [PaymentController::class, 'export'])->name('payments.export');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::resource('expense-categories', ExpenseCategoryController::class)->except('show');
    Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
    Route::post('expenses/{expense}/record-recurring', [ExpenseController::class, 'recordRecurring'])->name('expenses.record-recurring');
    Route::get('expenses-export', [ExpenseController::class, 'export'])->name('expenses.export');
    Route::resource('expenses', ExpenseController::class)->except('show');
    Route::get('reports/sales', [SalesReportController::class, 'index'])->name('reports.sales');
    Route::get('reports/sales/export', [SalesReportController::class, 'export'])->name('reports.sales.export');
    Route::get('payment-settings', [PaymentSettingsController::class, 'index'])->name('payment-settings.index');
    Route::put('payment-settings/{shop}', [PaymentSettingsController::class, 'update'])->name('payment-settings.update');
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::get('wallet-withdrawals', [WalletWithdrawalController::class, 'index'])->name('wallet-withdrawals.index');
});

Route::get('/{tenant:slug}', TenantPublicSiteController::class)->name('tenant.public-site');
