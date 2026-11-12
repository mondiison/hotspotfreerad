<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\ShopBrandingService;
use App\Support\ClientAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 2 (2026-11-13): a Client's "landing template" customization of
 * their own shop's hotspot portal -- banner image, welcome message, and
 * the shop's own support phone number (already-existing contact_phone).
 * Plain Blade + controller, not Livewire -- a one-form-per-shop settings
 * page has no need for WithFileUploads' extra machinery over a normal
 * multipart form post.
 */
class BrandingController extends Controller
{
    public function index(Request $request): View
    {
        return view('client.branding.index', [
            'shops' => ClientAccess::scopeShops(Shop::query(), $request->user())->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Shop $shop, ShopBrandingService $branding): RedirectResponse
    {
        ClientAccess::assertShop($shop, $request->user());

        $data = $request->validate($branding->rules());
        $branding->update($shop, $data);

        return redirect()->route('client.branding.index')->with('status', 'Portal branding updated for '.$shop->name.'.');
    }
}
