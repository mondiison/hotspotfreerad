<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package as InternetPackage;
use App\Models\Shop;
use App\Services\RadiusProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(): View
    {
        return view('admin.packages.index', [
            'packages' => InternetPackage::with('shop.tenant')->latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.packages.form', [
            'package' => new InternetPackage(),
            'shops' => Shop::with('tenant')->orderBy('name')->get(),
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
        return view('admin.packages.form', [
            'package' => $package,
            'shops' => Shop::with('tenant')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, InternetPackage $package, RadiusProvisioningService $radius): RedirectResponse
    {
        $package->update($this->validated($request, $package));
        $radius->syncPackageProfile($package);

        return redirect()->route('admin.packages.index')->with('status', 'Package updated and synced to RADIUS profile.');
    }

    public function destroy(InternetPackage $package): RedirectResponse
    {
        $package->delete();

        return redirect()->route('admin.packages.index')->with('status', 'Package deleted.');
    }

    private function validated(Request $request, ?InternetPackage $package = null): array
    {
        return $request->validate([
            'shop_id' => ['required', 'exists:shops,id'],
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
