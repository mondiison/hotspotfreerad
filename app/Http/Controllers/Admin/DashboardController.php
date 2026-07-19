<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Support\RadiusAccountingStats;
use App\Support\TenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request, RadiusAccountingStats $radiusStats): View
    {
        $user = $request->user();
        $shopQuery = TenantAccess::scopeShops(Shop::query(), $user);
        $routerQuery = TenantAccess::scopeRouters(Router::query(), $user);
        $packageQuery = TenantAccess::scopePackages(Package::query(), $user);
        $shopIds = (clone $shopQuery)->pluck('id');
        $routers = (clone $routerQuery)->with('shop.tenant')->get();
        $routers = $radiusStats->refreshRouterHealth($routers);
        $radiusSummary = $radiusStats->summary($routers);
        $activeSubscriptionCount = Subscription::query()
            ->whereIn('shop_id', $shopIds)
            ->where('expires_at', '>', now())
            ->count();
        $shops = (clone $shopQuery)->get();
        $paidRevenue = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', 'successful')
            ->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)'));
        $platformCommission = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', 'successful')
            ->sum('platform_fee_amount');
        $tenantNetRevenue = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', 'successful')
            ->sum(DB::raw('coalesce(nullif(tenant_net_amount, 0), coalesce(nullif(gross_amount, 0), amount) - platform_fee_amount)'));
        $totalExpenses = TenantAccess::scopeExpenses(Expense::query(), $user)->sum('amount');
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfDay();
        $currentMonthPaymentQuery = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', 'successful')
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query->whereBetween('paid_at', [$monthStart, $monthEnd])
                    ->orWhere(function ($query) use ($monthStart, $monthEnd): void {
                        $query->whereNull('paid_at')
                            ->whereBetween('created_at', [$monthStart, $monthEnd]);
                    });
            });
        $currentMonthGrossSales = (float) (clone $currentMonthPaymentQuery)
            ->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)'));
        $currentMonthPlatformCommission = (float) (clone $currentMonthPaymentQuery)
            ->sum('platform_fee_amount');
        $currentMonthTenantNet = (float) (clone $currentMonthPaymentQuery)
            ->sum(DB::raw('coalesce(nullif(tenant_net_amount, 0), coalesce(nullif(gross_amount, 0), amount) - platform_fee_amount)'));
        $currentMonthExpenses = (float) TenantAccess::scopeExpenses(Expense::query(), $user)
            ->whereDate('incurred_on', '>=', $monthStart->toDateString())
            ->whereDate('incurred_on', '<=', $monthEnd->toDateString())
            ->sum('amount');
        $currentMonthProfit = $currentMonthTenantNet - $currentMonthExpenses;
        $budgetCategoryCount = TenantAccess::scopeExpenseCategories(ExpenseCategory::query(), $user)
            ->whereNotNull('monthly_budget')
            ->where('monthly_budget', '>', 0)
            ->count();
        $budgetWatch = TenantAccess::scopeExpenses(
            Expense::query()->with(['category', 'tenant']),
            $user
        )
            ->whereDate('incurred_on', '>=', $monthStart->toDateString())
            ->whereDate('incurred_on', '<=', $monthEnd->toDateString())
            ->get()
            ->filter(fn (Expense $expense) => (float) ($expense->category?->monthly_budget ?? 0) > 0)
            ->groupBy(fn (Expense $expense) => $expense->expense_category_id)
            ->map(function ($expenses): array {
                $expense = $expenses->first();
                $budget = (float) $expense->category->monthly_budget;
                $spent = $expenses->sum(fn (Expense $expense) => (float) $expense->amount);
                $usage = round(($spent / $budget) * 100, 1);

                return [
                    'category_id' => $expense->category->id,
                    'category' => $expense->category->name,
                    'tenant' => $expense->tenant?->company_name,
                    'budget' => $budget,
                    'spent' => $spent,
                    'variance' => $budget - $spent,
                    'usage' => $usage,
                    'status' => $usage > 100 ? 'Over budget' : 'Near budget',
                ];
            })
            ->filter(fn (array $row) => $row['usage'] >= 80)
            ->sortByDesc('usage')
            ->take(5)
            ->values();
        $upcomingRecurringExpenses = TenantAccess::scopeExpenses(
            Expense::query()->with(['tenant', 'category']),
            $user
        )
            ->where('is_recurring', true)
            ->whereNotNull('next_due_on')
            ->whereBetween('next_due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('next_due_on')
            ->take(6)
            ->get();
        $overdueRecurringExpenses = TenantAccess::scopeExpenses(
            Expense::query()->with(['tenant', 'category']),
            $user
        )
            ->where('is_recurring', true)
            ->whereNotNull('next_due_on')
            ->whereDate('next_due_on', '<', now()->toDateString())
            ->orderBy('next_due_on')
            ->take(6)
            ->get();

        return view('admin.dashboard', [
            'tenantCount' => $user->isSuperAdmin() ? Tenant::count() : 1,
            'shopCount' => $shopIds->count(),
            'routerCount' => $routers->count(),
            'onlineRouterCount' => $routers->where('detected_status', 'Online')->count(),
            'packageCount' => (clone $packageQuery)->count(),
            'activePackageCount' => (clone $packageQuery)->where('is_active', true)->count(),
            'tenantBillingSummary' => $this->tenantBillingSummary($user, $shopIds->count(), $routers->count(), (clone $packageQuery)->count()),
            'platformBillingSummary' => $this->platformBillingSummary($user),
            'tenantWorkspaceSummary' => $this->tenantWorkspaceSummary($user),
            'tenantLaunchChecklist' => $this->tenantLaunchChecklist(
                $user,
                $shops,
                $routers->count(),
                (clone $packageQuery)->where('is_active', true)->count()
            ),
            'activeSessionCount' => $radiusSummary['active_session_count'],
            'onlineUserCount' => $radiusSummary['online_user_count'],
            'totalUsageBytes' => $radiusSummary['total_bytes'],
            'todayUsageBytes' => $radiusSummary['today_bytes'],
            'radiusAccountingReady' => $radiusSummary['ready'],
            'activeSubscriptionCount' => $activeSubscriptionCount,
            'paidRevenue' => $paidRevenue,
            'platformCommission' => $platformCommission,
            'tenantNetRevenue' => $tenantNetRevenue,
            'totalExpenses' => $totalExpenses,
            'estimatedProfit' => $tenantNetRevenue - $totalExpenses,
            'monthFinanceSummary' => [
                'period' => $monthStart->format('M Y'),
                'from' => $monthStart->toDateString(),
                'to' => $monthEnd->toDateString(),
                'gross_sales' => $currentMonthGrossSales,
                'platform_commission' => $currentMonthPlatformCommission,
                'tenant_net' => $currentMonthTenantNet,
                'expenses' => $currentMonthExpenses,
                'profit' => $currentMonthProfit,
                'margin' => $currentMonthTenantNet > 0 ? round(($currentMonthProfit / $currentMonthTenantNet) * 100, 1) : null,
            ],
            'budgetCategoryCount' => $budgetCategoryCount,
            'budgetWatch' => $budgetWatch,
            'upcomingRecurringExpenses' => $upcomingRecurringExpenses,
            'overdueRecurringExpenses' => $overdueRecurringExpenses,
            'onlineSessions' => $radiusStats->onlineSessions($routers),
            'recentSubscriptions' => Subscription::query()
                ->with(['shop.tenant', 'package'])
                ->whereIn('shop_id', $shopIds)
                ->latest()
                ->take(6)
                ->get(),
            'routerHealth' => $routers->sortByDesc('last_seen_at')->take(6),
            'setupSteps' => [
                [
                    'label' => 'Create tenant profile',
                    'complete' => $user->isSuperAdmin() ? Tenant::exists() : true,
                    'route' => $user->isSuperAdmin() ? 'admin.tenants.create' : null,
                ],
                [
                    'label' => 'Add first shop',
                    'complete' => $shopIds->isNotEmpty(),
                    'route' => 'admin.shops.create',
                ],
                [
                    'label' => 'Register MikroTik router',
                    'complete' => (clone $routerQuery)->exists(),
                    'route' => 'admin.routers.create',
                ],
                [
                    'label' => 'Publish data packages',
                    'complete' => (clone $packageQuery)->where('is_active', true)->exists(),
                    'route' => 'admin.packages.create',
                ],
            ],
        ]);
    }

    private function tenantBillingSummary(User $user, int $shopCount, int $routerCount, int $packageCount): ?array
    {
        if ($user->isSuperAdmin() || ! $user->tenant_id) {
            return null;
        }

        $tenant = Tenant::query()
            ->with('currentBillingSubscription.billingPlan')
            ->find($user->tenant_id);

        $subscription = $tenant?->currentBillingSubscription;
        $plan = $subscription?->billingPlan;

        return [
            'plan_name' => $plan?->name ?? 'No platform plan',
            'price' => $plan ? $plan->currency.' '.number_format((float) $plan->monthly_price, 2).'/month' : 'Choose a plan to unlock tenant growth',
            'status' => $subscription ? str($subscription->status)->replace('_', ' ')->title()->toString() : 'Not subscribed',
            'period_label' => $this->billingPeriodLabel($subscription),
            'usage' => [
                $this->limitUsage('Locations', $shopCount, $plan?->shop_limit),
                $this->limitUsage('Routers', $routerCount, $plan?->router_limit),
                $this->limitUsage('Packages', $packageCount, $plan?->package_limit),
            ],
        ];
    }

    private function tenantWorkspaceSummary(User $user): ?array
    {
        if ($user->isSuperAdmin() || ! $user->tenant_id) {
            return null;
        }

        $tenant = Tenant::find($user->tenant_id);

        if (! $tenant) {
            return null;
        }

        return [
            'company_name' => $tenant->company_name,
            'slug' => $tenant->slug,
            'owner_email' => $tenant->owner_email,
            'public_url' => $tenant->publicUrl(),
            'public_site_enabled' => $tenant->public_site_enabled,
        ];
    }

    private function tenantLaunchChecklist(User $user, $shops, int $routerCount, int $activePackageCount): ?array
    {
        if ($user->isSuperAdmin() || ! $user->tenant_id) {
            return null;
        }

        $tenant = Tenant::find($user->tenant_id);

        if (! $tenant) {
            return null;
        }

        $paymentReadyShopCount = $shops->filter(fn (Shop $shop) => $shop->hasCompleteFlutterwaveCredentials())->count();

        return [
            [
                'label' => 'Customize tenant brand',
                'detail' => 'Logo, hero images, public copy, and brand color.',
                'complete' => filled($tenant->brand_color) && ($tenant->logo_image_path || $tenant->hero_image_path || filled($tenant->public_site_tagline)),
                'route' => 'admin.brand.edit',
                'action' => 'Brand',
                'status' => 'Brand assets and public copy make the tenant page credible.',
            ],
            [
                'label' => 'Add hotspot location',
                'detail' => 'Create the shop or coverage area customers will buy from.',
                'complete' => $shops->isNotEmpty(),
                'route' => $shops->isNotEmpty() ? 'admin.shops.index' : 'admin.shops.create',
                'action' => $shops->isNotEmpty() ? 'Review shops' : 'Add shop',
                'status' => $shops->isNotEmpty() ? $shops->count().' location added.' : 'A shop is required before routers, packages, and payments are useful.',
            ],
            [
                'label' => 'Register MikroTik router',
                'detail' => 'Add NAS details and sync it to FreeRADIUS.',
                'complete' => $routerCount > 0,
                'route' => $routerCount > 0 ? 'admin.routers.index' : 'admin.routers.create',
                'action' => $routerCount > 0 ? 'Review routers' : 'Add router',
                'status' => $routerCount > 0 ? $routerCount.' router registered.' : 'Router setup connects MikroTik to FreeRADIUS.',
            ],
            [
                'label' => 'Publish customer package',
                'detail' => 'Set data, speed, uptime, and pricing for the captive portal.',
                'complete' => $activePackageCount > 0,
                'route' => $activePackageCount > 0 ? 'admin.packages.index' : 'admin.packages.create',
                'action' => $activePackageCount > 0 ? 'Review plans' : 'Add package',
                'status' => $activePackageCount > 0 ? $activePackageCount.' active package published.' : 'Customers need an active package before they can buy access.',
            ],
            [
                'label' => 'Connect payment account',
                'detail' => $shops->isEmpty()
                    ? 'Create a shop first, then add Flutterwave credentials.'
                    : 'At least one shop should have complete Flutterwave credentials.',
                'complete' => $paymentReadyShopCount > 0,
                'route' => $shops->isEmpty() ? 'admin.shops.create' : 'admin.payment-settings.index',
                'action' => $shops->isEmpty() ? 'Add shop' : 'Payment setup',
                'status' => $paymentReadyShopCount > 0 ? $paymentReadyShopCount.' shop ready for customer payments.' : 'Payments can use tenant-owned credentials when configured.',
            ],
        ];
    }

    private function platformBillingSummary(User $user): ?array
    {
        if (! $user->isSuperAdmin()) {
            return null;
        }

        return [
            'plan_count' => BillingPlan::count(),
            'active_subscription_count' => TenantBillingSubscription::whereIn('status', ['active', 'trialing'])->count(),
            'past_due_subscription_count' => TenantBillingSubscription::where('status', 'past_due')->count(),
            'monthly_recurring_revenue' => TenantBillingSubscription::whereIn('status', ['active', 'trialing'])->sum('amount'),
        ];
    }

    private function limitUsage(string $label, int $used, ?int $limit): array
    {
        return [
            'label' => $label,
            'used' => $used,
            'limit' => $limit,
            'limit_label' => $limit === null ? 'Unlimited' : number_format($limit),
            'percent' => $limit === null ? 100 : min(100, (int) round(($used / max(1, $limit)) * 100)),
            'is_limited' => $limit !== null,
        ];
    }

    private function billingPeriodLabel(?TenantBillingSubscription $subscription): string
    {
        if (! $subscription) {
            return 'No renewal date yet';
        }

        if ($subscription->status === 'trialing' && $subscription->trial_ends_at) {
            return 'Trial ends '.$subscription->trial_ends_at->toFormattedDateString();
        }

        if ($subscription->current_period_ends_at) {
            return ($subscription->current_period_ends_at->isPast() ? 'Expired ' : 'Renews ').$subscription->current_period_ends_at->toFormattedDateString();
        }

        return 'Billing period not set';
    }
}
