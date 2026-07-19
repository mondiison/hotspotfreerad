<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\PaymentSettingsService;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentSettingsController extends Controller
{
    public function index(Request $request): View
    {
        $shops = TenantAccess::scopeShops(
            Shop::query()->with('tenant')->withCount('payments')->orderBy('name'),
            $request->user()
        )->get();

        return view('admin.payment-settings.index', [
            'shops' => $shops,
            'summary' => [
                'shops' => $shops->count(),
                'configured' => $shops->filter->hasCompleteFlutterwaveCredentials()->count(),
                'webhook_ready' => $shops->filter->hasFlutterwaveWebhookSecret()->count(),
            ],
        ]);
    }

    public function update(Request $request, Shop $shop, PaymentSettingsService $settings): RedirectResponse
    {
        $settings->update($shop, $settings->validated($request), $request->user());

        return redirect()->route('admin.payment-settings.index')->with('status', 'Payment settings updated for '.$shop->name.'.');
    }
}
