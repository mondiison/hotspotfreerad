<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Tenant;
use App\Support\RadiusAccountingStats;
use App\Support\TenantAccess;
use Illuminate\View\View;

class TopologyController extends Controller
{
    public function index(RadiusAccountingStats $radiusStats): View
    {
        $user = request()->user();

        $routers = TenantAccess::scopeRouters(Router::with('shop.tenant'), $user)->get();
        $radiusStats->refreshRouterHealth($routers);

        // $routers is already tenant-scoped via TenantAccess::scopeRouters(), so the
        // tenant IDs derived from it are safe to query without further scoping.
        $tenantIds = $routers->pluck('shop.tenant_id')->filter()->unique();

        $tenants = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->get()
            ->map(function (Tenant $tenant) use ($routers): array {
                $tenantRouters = $routers->filter(fn (Router $router) => $router->shop?->tenant_id === $tenant->id);
                $shops = $tenantRouters->groupBy('shop_id')->map(function ($shopRouters) {
                    return [
                        'shop' => $shopRouters->first()->shop,
                        'routers' => $shopRouters->values(),
                    ];
                })->values();

                return [
                    'tenant' => $tenant,
                    'shops' => $shops,
                    'router_count' => $tenantRouters->count(),
                    'online_count' => $tenantRouters->where('detected_status', 'Online')->count(),
                ];
            })
            ->filter(fn (array $row): bool => $row['shops']->isNotEmpty())
            ->values();

        return view('admin.topology.index', [
            'tenants' => $tenants,
        ]);
    }
}
