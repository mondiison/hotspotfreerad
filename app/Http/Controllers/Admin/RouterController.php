<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Shop;
use App\Services\MikroTikProvisioningService;
use App\Services\RouterManagementService;
use App\Support\BillingPlanLimits;
use App\Support\RadiusAccountingStats;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RouterController extends Controller
{
    public function index(RadiusAccountingStats $radiusStats): View
    {
        $user = request()->user();
        $radiusStats->refreshRouterHealth(TenantAccess::scopeRouters(Router::with('shop.tenant'), $user)->get());

        $query = TenantAccess::scopeRouters(Router::with('shop.tenant'), $user)
            ->when(request('search'), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('nas_identifier', 'like', "%{$search}%")
                        ->orWhere('wireguard_internal_ip', 'like', "%{$search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(request()->filled('status'), fn ($query) => $query->where('is_online', request('status') === 'online'));

        return view('admin.routers.index', [
            'routers' => $query->latest()->paginate(15)->withQueryString(),
            'filters' => request()->only(['search', 'status']),
        ]);
    }

    public function create(): View
    {
        $user = request()->user();

        return view('admin.routers.form', [
            'router' => new Router,
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
            'billingUsage' => BillingPlanLimits::usageSummary($user, 'routers'),
        ]);
    }

    public function show(Router $router, MikroTikProvisioningService $mikroTik): View
    {
        TenantAccess::assertRouter($router, request()->user());

        return view('admin.routers.show', [
            'router' => $router->load('shop.tenant'),
            'script' => $mikroTik->generateScript($router),
            'loginTemplate' => $mikroTik->generateLoginTemplate(),
            'provisioningConfig' => [
                'portal_url' => rtrim(config('app.url'), '/').'/hotspot/portal',
                'radius_server_ip' => config('services.radius.server_ip'),
                'wireguard_endpoint_host' => config('services.wireguard.endpoint_host'),
                'wireguard_endpoint_port' => config('services.wireguard.endpoint_port'),
                'wireguard_public_key' => config('services.wireguard.public_key'),
                'hotspot_dns_name' => config('services.mikrotik.hotspot_dns_name'),
            ],
        ]);
    }

    public function store(Request $request, RouterManagementService $routers): RedirectResponse
    {
        $routers->create($routers->validated($request), $request->user());

        return redirect()->route('admin.routers.index')->with('status', 'Router created and synced to RADIUS nas.');
    }

    public function edit(Router $router): View
    {
        TenantAccess::assertRouter($router, request()->user());
        $user = request()->user();

        return view('admin.routers.form', [
            'router' => $router,
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
            'billingUsage' => null,
        ]);
    }

    public function update(Request $request, Router $router, RouterManagementService $routers): RedirectResponse
    {
        $routers->update($router, $routers->validated($request, $router), $request->user());

        return redirect()->route('admin.routers.index')->with('status', 'Router updated and synced to RADIUS nas.');
    }

    public function destroy(Request $request, Router $router, RouterManagementService $routers): RedirectResponse
    {
        $routers->delete($router, $request->user());

        return redirect()->route('admin.routers.index')->with('status', 'Router deleted.');
    }
}
