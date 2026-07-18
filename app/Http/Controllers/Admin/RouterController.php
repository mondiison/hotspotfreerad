<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Shop;
use App\Services\MikroTikProvisioningService;
use App\Services\RadiusProvisioningService;
use App\Support\RadiusAccountingStats;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        ]);
    }

    public function create(): View
    {
        $user = request()->user();

        return view('admin.routers.form', [
            'router' => new Router(),
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
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
                'portal_url' => rtrim(config('app.url'), '/') . '/hotspot/portal',
                'radius_server_ip' => config('services.radius.server_ip'),
                'wireguard_endpoint_host' => config('services.wireguard.endpoint_host'),
                'wireguard_endpoint_port' => config('services.wireguard.endpoint_port'),
                'wireguard_public_key' => config('services.wireguard.public_key'),
                'hotspot_dns_name' => config('services.mikrotik.hotspot_dns_name'),
            ],
        ]);
    }

    public function store(Request $request, RadiusProvisioningService $radius): RedirectResponse
    {
        $data = $this->validated($request);
        BillingPlanLimits::assertCanCreateRouter($request->user());

        $router = Router::create($data);
        $radius->syncRouter($router);

        return redirect()->route('admin.routers.index')->with('status', 'Router created and synced to RADIUS nas.');
    }

    public function edit(Router $router): View
    {
        TenantAccess::assertRouter($router, request()->user());
        $user = request()->user();

        return view('admin.routers.form', [
            'router' => $router,
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Router $router, RadiusProvisioningService $radius): RedirectResponse
    {
        TenantAccess::assertRouter($router, $request->user());

        $data = $this->validated($request, $router);

        if (blank($data['shared_secret'] ?? null)) {
            unset($data['shared_secret']);
        }

        $router->update($data);
        $radius->syncRouter($router);

        return redirect()->route('admin.routers.index')->with('status', 'Router updated and synced to RADIUS nas.');
    }

    public function destroy(Router $router): RedirectResponse
    {
        TenantAccess::assertRouter($router, request()->user());

        $router->delete();

        return redirect()->route('admin.routers.index')->with('status', 'Router deleted.');
    }

    private function validated(Request $request, ?Router $router = null): array
    {
        return $request->validate([
            'shop_id' => ['required', TenantAccess::shopExistsRule($request->user())],
            'name' => ['required', 'string', 'max:255'],
            'nas_identifier' => ['required', 'string', 'max:255', Rule::unique('routers')->ignore($router)],
            'wireguard_internal_ip' => ['required', 'ip', Rule::unique('routers')->ignore($router)],
            'shared_secret' => [$router ? 'nullable' : 'required', 'string', 'max:255'],
            'is_online' => ['nullable', 'boolean'],
        ]) + ['is_online' => false];
    }
}
