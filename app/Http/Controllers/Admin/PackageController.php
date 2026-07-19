<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package as InternetPackage;
use App\Models\Shop;
use App\Services\PackageManagementService;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(): View
    {
        return view('admin.packages.index', [
            'filters' => request()->only(['search', 'status']),
        ]);
    }

    public function create(): View
    {
        $user = request()->user();

        return view('admin.packages.form', [
            'package' => new InternetPackage,
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
            'billingUsage' => BillingPlanLimits::usageSummary($user, 'packages'),
        ]);
    }

    public function store(Request $request, PackageManagementService $packages): RedirectResponse
    {
        $packages->create($packages->validated($request), $request->user());

        return redirect()->route('admin.packages.index')->with('status', 'Package created and synced to RADIUS profile.');
    }

    public function edit(InternetPackage $package): View
    {
        TenantAccess::assertPackage($package, request()->user());
        $user = request()->user();

        return view('admin.packages.form', [
            'package' => $package,
            'shops' => TenantAccess::scopeShops(Shop::with('tenant'), $user)->orderBy('name')->get(),
            'billingUsage' => null,
        ]);
    }

    public function update(Request $request, InternetPackage $package, PackageManagementService $packages): RedirectResponse
    {
        $packages->update($package, $packages->validated($request, $package), $request->user());

        return redirect()->route('admin.packages.index')->with('status', 'Package updated and synced to RADIUS profile.');
    }

    public function destroy(InternetPackage $package): RedirectResponse
    {
        TenantAccess::assertPackage($package, request()->user());

        $package->delete();

        return redirect()->route('admin.packages.index')->with('status', 'Package deleted.');
    }
}
