<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
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
        $paidRevenue = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', 'successful')
            ->sum('amount');

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
            'activeSessionCount' => $radiusSummary['active_session_count'],
            'onlineUserCount' => $radiusSummary['online_user_count'],
            'totalUsageBytes' => $radiusSummary['total_bytes'],
            'todayUsageBytes' => $radiusSummary['today_bytes'],
            'radiusAccountingReady' => $radiusSummary['ready'],
            'activeSubscriptionCount' => $activeSubscriptionCount,
            'paidRevenue' => $paidRevenue,
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
