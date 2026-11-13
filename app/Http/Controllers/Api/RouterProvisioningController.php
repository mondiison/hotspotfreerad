<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Shop;
use App\Services\MikroTikProvisioningService;
use App\Services\RouterManagementService;
use App\Services\RouterOsConnectionService;
use App\Services\SecurityActivityService;
use App\Services\ZeroTierMembershipSyncService;
use App\Support\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            // Same three options the web wizard's Identity step offers --
            // added here specifically for a router sitting behind CGNAT,
            // where raw WireGuard can never form a tunnel at all (the Pi
            // itself being behind CGNAT is the identical problem from the
            // other end). zerotier_node_id is deliberately NOT required
            // here even when this is zerotier/wireguard_zerotier -- unlike
            // RouterManagementService::rules()' stricter web-wizard
            // version, this endpoint runs before the router has ever
            // enabled ZeroTier at all (that only happens once THIS
            // bootstrap script actually runs), so there's no node ID yet
            // to supply -- it's entered later, once known, the same
            // "no working WireGuard yet" manual-entry path already
            // documented in CLAUDE.md for this exact scenario.
            'tunnel_mode' => ['nullable', Rule::in(['wireguard', 'wireguard_zerotier', 'zerotier'])],
        ]);

        $shop = TenantAccess::scopeShops(Shop::query(), $user)->findOrFail($validated['shop_id']);

        $router = $routers->create([
            'shop_id' => $shop->id,
            'name' => $validated['name'],
            'nas_identifier' => $routers->suggestedNasIdentifier($shop),
            'wireguard_internal_ip' => $routers->suggestedWireguardInternalIp(),
            'shared_secret' => $routers->suggestedSharedSecret(),
            'tunnel_mode' => $validated['tunnel_mode'] ?? 'wireguard',
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
                'tunnel_mode' => $router->tunnel_mode,
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
     * Closes the gap confirmed live (2026-11-13) testing this feature's
     * first real CGNAT-site router: a router choosing zerotier/wireguard_zerotier
     * generates its own ZeroTier node identity locally the moment the
     * bootstrap script's `/zerotier enable` line runs, and there was
     * previously no way to get that node ID authorized without a human
     * reading it off the router's own console and then SSHing into the Pi
     * to call the controller API directly -- the latter step genuinely
     * cannot be done by a tenant_admin at all (no shell access, and the
     * equivalent web-admin button is super_admin-only per the Phase 1
     * router lockdown).
     *
     * The phone already reads the node ID itself now (same REST connection
     * it just used to push the bootstrap script -- see
     * RouterProvisioningRepository.fetchZeroTierNodeId() on the Dart side)
     * and calls this endpoint instead of asking a human to bridge the gap.
     * Running *as the backend* is what makes this actually work smoothly:
     * ZeroTierControllerService's calls to the self-hosted controller
     * (127.0.0.1:9993) only ever succeed from the Pi itself, which is
     * exactly where this code already runs in production -- no SSH, no
     * sudo, no manual IP picking (RouterManagementService::suggestedZeroTierIp()
     * allocates the next free 10.9.0.x address the same way
     * suggestedWireguardInternalIp() already does for the WireGuard range).
     */
    public function saveZeroTierNodeId(
        Request $request,
        Router $router,
        RouterManagementService $routers,
        ZeroTierMembershipSyncService $zeroTierMembers,
        RouterOsConnectionService $routerOs,
    ): JsonResponse {
        TenantAccess::assertRouter($router, $request->user());

        if (! in_array($router->tunnel_mode, ['wireguard_zerotier', 'zerotier'], true)) {
            return response()->json(['message' => 'This router is not configured to use ZeroTier.'], 422);
        }

        $validated = $request->validate([
            'node_id' => ['required', 'string', 'max:16', Rule::unique('routers', 'zerotier_node_id')->ignore($router->id)],
        ]);

        $router->zerotier_node_id = $validated['node_id'];

        if (blank($router->zerotier_ip)) {
            $router->zerotier_ip = $routers->suggestedZeroTierIp();
        }

        $router->save();

        $authorize = $zeroTierMembers->authorizeRouter($router);

        if (! $authorize['success']) {
            return response()->json([
                'zerotier_ip' => $router->zerotier_ip,
                'authorized' => false,
                'error' => $authorize['error'],
            ]);
        }

        // Best-effort, same as the web admin's own "Authorize & connect now"
        // button -- the router may not have propagated the new authorization
        // yet, in which case hotspot:auto-provision-routers' own 5-minute
        // retry (already running) picks this up without any further action.
        $sync = $routerOs->syncZeroTierNetworkMembership($router);

        return response()->json([
            'zerotier_ip' => $router->zerotier_ip,
            'authorized' => true,
            'network_sync' => $sync['success'],
        ]);
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
