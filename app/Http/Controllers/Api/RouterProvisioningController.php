<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Shop;
use App\Services\MikroTikProvisioningService;
use App\Services\RouterManagementService;
use App\Services\SecurityActivityService;
use App\Support\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reseller model Phase 3 (2026-11-14): the phone-facing half of zero-touch
 * router provisioning. The Laravel backend can never reach a brand-new
 * router's own factory-default local network at all -- RouterOsConnectionService::
 * candidateHosts() only ever returns wireguard_internal_ip/zerotier_ip, i.e. a
 * router already on the tunnel -- so the one-time bootstrap push has to run
 * on the phone itself, over the router's own local Wi-Fi. This controller's
 * only job is the two things the phone genuinely needs the backend for:
 * create the Router row (so it has a real identity + auto-generated WireGuard/
 * API credentials, exactly like the web wizard already does) and hand back
 * the exact same bootstrap script text super_admin would otherwise view and
 * paste by hand -- the one place in the whole system that returns raw
 * WireGuard/API secrets over the network, hence the audit log below.
 *
 * Deliberately never added to StaffPermissions::ROUTE_PERMISSIONS -- that's
 * what makes these routes tenant_admin-only (and super_admin, redundantly):
 * both bypass that map entirely, tenant_staff is denied by default for any
 * route missing from it, matching the admin.billing/admin.tenants precedent.
 */
class RouterProvisioningController extends Controller
{
    public function store(Request $request, RouterManagementService $routers, MikroTikProvisioningService $provisioning): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $shop = TenantAccess::scopeShops(Shop::query(), $user)->findOrFail($validated['shop_id']);

        $router = $routers->create([
            'shop_id' => $shop->id,
            'name' => $validated['name'],
            'nas_identifier' => $routers->suggestedNasIdentifier($shop),
            'wireguard_internal_ip' => $routers->suggestedWireguardInternalIp(),
            'shared_secret' => $routers->suggestedSharedSecret(),
        ], $user);

        $script = $provisioning->generateBootstrapScript($router);

        app(SecurityActivityService::class)->log(
            $user,
            'router_bootstrap_script_issued',
            'Zero-touch bootstrap script issued to the mobile app.',
            [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'shop_id' => $shop->id,
                'shop' => $shop->name,
            ],
            $request
        );

        return response()->json([
            'router' => [
                'id' => $router->id,
                'name' => $router->name,
                'nas_identifier' => $router->nas_identifier,
                'public_token' => $router->public_token,
            ],
            'bootstrap_script' => $script,
        ], 201);
    }

    public function markBootstrapPushed(Request $request, Router $router): JsonResponse
    {
        TenantAccess::assertRouter($router, $request->user());

        $router->forceFill(['bootstrap_pushed_at' => now()])->save();

        return response()->json(['bootstrap_pushed_at' => $router->bootstrap_pushed_at]);
    }

    /**
     * Deliberately DB-only -- no live RouterOS call here. hotspot:auto-provision-routers
     * is already retrying this router every 5 minutes in the background the
     * moment bootstrap_pushed_at is set; this endpoint just reports what that
     * job has already observed, cheap enough for the phone to poll every few
     * seconds without hammering a live connection.
     */
    public function status(Request $request, Router $router): JsonResponse
    {
        TenantAccess::assertRouter($router, $request->user());

        return response()->json([
            'bootstrap_pushed_at' => $router->bootstrap_pushed_at,
            'auto_provisioned_at' => $router->auto_provisioned_at,
            'is_online' => (bool) $router->is_online,
        ]);
    }
}
