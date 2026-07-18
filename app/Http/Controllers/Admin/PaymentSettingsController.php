<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
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

    public function update(Request $request, Shop $shop): RedirectResponse
    {
        TenantAccess::assertShop($shop, $request->user());

        $data = $request->validate([
            'flutterwave_client_id' => ['nullable', 'string', 'required_with:flutterwave_client_secret'],
            'flutterwave_client_secret' => ['nullable', 'string', 'required_with:flutterwave_client_id'],
            'flutterwave_webhook_secret' => ['nullable', 'string'],
            'clear_flutterwave_credentials' => ['nullable', 'boolean'],
            'clear_flutterwave_webhook_secret' => ['nullable', 'boolean'],
        ]);

        $updates = [];

        if ($request->boolean('clear_flutterwave_credentials')) {
            $updates['flutterwave_client_id'] = null;
            $updates['flutterwave_client_secret'] = null;
        } elseif (filled($data['flutterwave_client_id'] ?? null) && filled($data['flutterwave_client_secret'] ?? null)) {
            $updates['flutterwave_client_id'] = $data['flutterwave_client_id'];
            $updates['flutterwave_client_secret'] = $data['flutterwave_client_secret'];
        }

        if ($request->boolean('clear_flutterwave_webhook_secret')) {
            $updates['flutterwave_webhook_secret'] = null;
        } elseif (filled($data['flutterwave_webhook_secret'] ?? null)) {
            $updates['flutterwave_webhook_secret'] = $data['flutterwave_webhook_secret'];
        }

        if ($updates !== []) {
            $shop->update($updates);
        }

        return redirect()->route('admin.payment-settings.index')->with('status', 'Payment settings updated for '.$shop->name.'.');
    }
}
