<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Tenant;
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
            ->when(request()->filled('status'), fn ($query) => $query->where('is_active', request('status') === 'active'));

        return view('admin.shops.index', [
            'shops' => $query->latest()->paginate(15)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $user = request()->user();

        return view('admin.shops.form', [
            'shop' => new Shop(),
            'tenants' => $user->isSuperAdmin()
                ? Tenant::orderBy('company_name')->get()
                : Tenant::whereKey($user->tenant_id)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        BillingPlanLimits::assertCanCreateShop($request->user());

        Shop::create($data);

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
        ]);
    }

    public function update(Request $request, Shop $shop): RedirectResponse
    {
        TenantAccess::assertShop($shop, $request->user());

        $data = $this->validated($request);

        foreach (['flutterwave_client_id', 'flutterwave_client_secret', 'flutterwave_webhook_secret'] as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }

        $shop->update($data);

        return redirect()->route('admin.shops.index')->with('status', 'Shop updated.');
    }

    public function destroy(Shop $shop): RedirectResponse
    {
        TenantAccess::assertShop($shop, request()->user());

        $shop->delete();

        return redirect()->route('admin.shops.index')->with('status', 'Shop deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'location_city' => ['nullable', 'string', 'max:255'],
            'flutterwave_client_id' => ['nullable', 'string'],
            'flutterwave_client_secret' => ['nullable', 'string'],
            'flutterwave_webhook_secret' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => false];

        if (! $request->user()->isSuperAdmin()) {
            $data['tenant_id'] = $request->user()->tenant_id;
        }

        return $data;
    }
}
