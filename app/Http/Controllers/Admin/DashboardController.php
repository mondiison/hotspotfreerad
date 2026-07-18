<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
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
}
