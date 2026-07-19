<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\ShopManagementService;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopController extends Controller
{
    public function index(): View
    {
        $user = request()->user();
        $query = TenantAccess::scopeShops(Shop::with('tenant')->withCount(['routers', 'packages']), $user)
            ->when(request('search'), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('location_city', 'like', "%{$search}%")
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('company_name', 'like', "%{$search}%"));
                });
            })
            ->when(request()->filled('status'), fn ($query) => $query->where('is_active', request('status') === 'active'))
            ->when(request('payments') === 'configured', fn ($query) => $query
                ->whereNotNull('flutterwave_client_id')
                ->whereNotNull('flutterwave_client_secret'))
            ->when(request('payments') === 'unconfigured', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->whereNull('flutterwave_client_id')
                    ->orWhereNull('flutterwave_client_secret')));

        return view('admin.shops.index', [
            'shops' => $query->latest()->paginate(15)->withQueryString(),
            'filters' => request()->only(['search', 'status', 'payments']),
        ]);
    }

    public function create(): View
    {
        $user = request()->user();

        return view('admin.shops.form', [
            'shop' => new Shop,
            'tenants' => $user->isSuperAdmin()
                ? Tenant::orderBy('company_name')->get()
                : Tenant::whereKey($user->tenant_id)->get(),
            'billingUsage' => BillingPlanLimits::usageSummary($user, 'shops'),
        ]);
    }

    public function store(Request $request, ShopManagementService $shops): RedirectResponse
    {
        $shops->create($shops->validated($request), $request->user());

        return redirect()->route('admin.shops.index')->with('status', 'Shop created.');
    }

    public function edit(Shop $shop): View
    {
        TenantAccess::assertShop($shop, request()->user());
        $user = request()->user();

        return view('admin.shops.form', [
            'shop' => $shop,
            'tenants' => $user->isSuperAdmin()
                ? Tenant::orderBy('company_name')->get()
                : Tenant::whereKey($user->tenant_id)->get(),
            'billingUsage' => null,
        ]);
    }

    public function update(Request $request, Shop $shop, ShopManagementService $shops): RedirectResponse
    {
        $shops->update($shop, $shops->validated($request), $request->user());

        return redirect()->route('admin.shops.index')->with('status', 'Shop updated.');
    }

    public function destroy(Request $request, Shop $shop, ShopManagementService $shops): RedirectResponse
    {
        $shops->delete($shop, $request->user());

        return redirect()->route('admin.shops.index')->with('status', 'Shop deleted.');
    }
}
