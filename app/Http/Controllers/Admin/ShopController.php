<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopController extends Controller
{
    public function index(): View
    {
        return view('admin.shops.index', [
            'shops' => Shop::with('tenant')->latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.shops.form', [
            'shop' => new Shop(),
            'tenants' => Tenant::orderBy('company_name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Shop::create($this->validated($request));

        return redirect()->route('admin.shops.index')->with('status', 'Shop created.');
    }

    public function edit(Shop $shop): View
    {
        return view('admin.shops.form', [
            'shop' => $shop,
            'tenants' => Tenant::orderBy('company_name')->get(),
        ]);
    }

    public function update(Request $request, Shop $shop): RedirectResponse
    {
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
        $shop->delete();

        return redirect()->route('admin.shops.index')->with('status', 'Shop deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'location_city' => ['nullable', 'string', 'max:255'],
            'flutterwave_client_id' => ['nullable', 'string'],
            'flutterwave_client_secret' => ['nullable', 'string'],
            'flutterwave_webhook_secret' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => false];
    }
}
