<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package as InternetPackage;
use App\Models\Shop;
use App\Services\RadiusProvisioningService;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(): View
    {
        $user = request()->user();
        $query = TenantAccess::scopePackages(InternetPackage::with('shop.tenant'), $user)
            ->when(request('search'), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('radius_group_name', 'like', "%{$search}%")
                        ->orWhere('speed_limit_profile', 'like', "%{$search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(request()->filled('status'), fn ($query) => $query->where('is_active', request('status') === 'active'));

        return view('admin.packages.index', [
            'packages' => $query->latest()->paginate(15)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $user = request()->user();

        return view('admin.packages.form', [
            'package' => new InternetPackage(),
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, RadiusProvisioningService $radius): RedirectResponse
    {
        $package = InternetPackage::create($this->validated($request));
        $radius->syncPackageProfile($package);

        return redirect()->route('admin.packages.index')->with('status', 'Package created and synced to RADIUS profile.');
    }

    public function edit(InternetPackage $package): View
    {
        TenantAccess::assertPackage($package, request()->user());
        $user = request()->user();

        return view('admin.packages.form', [
            'package' => $package,
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, InternetPackage $package, RadiusProvisioningService $radius): RedirectResponse
    {
        TenantAccess::assertPackage($package, $request->user());

        $package->update($this->validated($request, $package));
        $radius->syncPackageProfile($package);

        return redirect()->route('admin.packages.index')->with('status', 'Package updated and synced to RADIUS profile.');
    }

    public function destroy(InternetPackage $package): RedirectResponse
    {
        TenantAccess::assertPackage($package, request()->user());

        $package->delete();

        return redirect()->route('admin.packages.index')->with('status', 'Package deleted.');
    }

    private function validated(Request $request, ?InternetPackage $package = null): array
    {
        return $request->validate([
            'shop_id' => ['required', TenantAccess::shopExistsRule($request->user())],
            'name' => ['required', 'string', 'max:255'],
            'radius_group_name' => ['nullable', 'string', 'max:64', Rule::unique('packages')->ignore($package)],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'limit_uptime_seconds' => ['required', 'integer', 'min:60'],
            'data_limit_bytes' => ['nullable', 'integer', 'min:1'],
            'speed_limit_profile' => ['required', 'string', 'max:255'],
            'fup_data_threshold_bytes' => ['nullable', 'integer', 'min:1'],
            'fup_speed_limit_profile' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => false];
    }
}
