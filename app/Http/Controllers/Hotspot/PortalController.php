<?php

namespace App\Http\Controllers\Hotspot;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\RadiusProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PortalController extends Controller
{
    private const TEST_ACCESS_PASSWORD = 'authenticated_device_pass';

    public function show(Request $request): View
    {
        $validated = $request->validate([
            'mac' => ['nullable', 'string', 'max:64'],
            'nasid' => ['nullable', 'string', 'max:255'],
            'link-login' => ['nullable', 'string', 'max:2048'],
            'link-orig' => ['nullable', 'string', 'max:2048'],
        ]);

        if (blank($validated['mac'] ?? null) || blank($validated['nasid'] ?? null)) {
            return view('hotspot.missing-parameters', [
                'macAddress' => $validated['mac'] ?? null,
                'nasIdentifier' => $validated['nasid'] ?? null,
            ]);
        }

        $router = Router::query()
            ->with(['shop.tenant', 'shop.packages' => fn ($query) => $query->where('is_active', true)->orderBy('price')])
            ->where('nas_identifier', $validated['nasid'])
            ->first();

        if (! $router) {
            return view('hotspot.unknown-router', [
                'macAddress' => $validated['mac'],
                'nasIdentifier' => $validated['nasid'],
            ]);
        }

        return view('hotspot.portal', [
            'router' => $router,
            'shop' => $router->shop,
            'packages' => $router->shop->packages,
            'macAddress' => $validated['mac'],
            'loginUrl' => $validated['link-login'] ?? null,
            'originalUrl' => $validated['link-orig'] ?? null,
        ]);
    }

    public function grant(Request $request, RadiusProvisioningService $radius): RedirectResponse|View
    {
        $validated = $request->validate([
            'mac' => ['required', 'string', 'max:64'],
            'nasid' => ['required', 'string', 'max:255'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'link-login' => ['nullable', 'string', 'max:2048'],
            'link-orig' => ['nullable', 'string', 'max:2048'],
        ]);

        $router = Router::query()
            ->with('shop')
            ->where('nas_identifier', $validated['nasid'])
            ->first();

        if (! $router) {
            return view('hotspot.unknown-router', [
                'macAddress' => $validated['mac'],
                'nasIdentifier' => $validated['nasid'],
            ]);
        }

        $package = Package::query()
            ->where('shop_id', $router->shop_id)
            ->where('is_active', true)
            ->findOrFail($validated['package_id']);

        $subscription = DB::transaction(function () use ($router, $package, $validated, $radius) {
            Customer::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $validated['mac'],
                ],
                []
            );

            $subscription = Subscription::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $validated['mac'],
                ],
                [
                    'package_id' => $package->id,
                    'starts_at' => now(),
                    'expires_at' => now()->addSeconds($package->limit_uptime_seconds),
                    'is_throttled' => false,
                ]
            );

            $radius->grantSubscriptionAccess($subscription, self::TEST_ACCESS_PASSWORD);

            return $subscription;
        });

        return view('hotspot.access-granted', [
            'router' => $router,
            'package' => $package,
            'subscription' => $subscription,
            'macAddress' => $validated['mac'],
            'username' => $validated['mac'],
            'password' => self::TEST_ACCESS_PASSWORD,
            'loginUrl' => $validated['link-login'] ?? null,
            'originalUrl' => $validated['link-orig'] ?? null,
        ]);
    }
}
