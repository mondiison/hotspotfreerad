<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\RouterResource;
use App\Models\Router;
use App\Services\RouterOsConnectionService;
use App\Services\SecurityActivityService;
use App\Support\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The phone-facing "remote access" feature (2026-11-18) -- NOT a console
 * proxy. Winbox/SSH/WebFig all need a direct network path to the router's
 * management IP, which only the backend has today (via the WireGuard/
 * ZeroTier tunnel) -- a phone would need a VPN client embedded in the app
 * to get that same reach, which this doesn't attempt. Instead, this hands
 * tenant_admin the router's tunnel IP + a dedicated, read-only RouterOS
 * login (see Router::VIEWER_USERNAME / MikroTikProvisioningService::
 * viewerGroupPolicy()) so they can open their own Winbox/SSH/WebFig
 * client from any device that already has reachability (their laptop over
 * its own WireGuard peer, or being physically on-site) -- the admin's own
 * device needs real network access to that IP either way; this endpoint
 * only ever supplies the credentials, never a connection.
 *
 * That viewer account is deliberately read-only -- RouterOS user-group
 * policy has no "write, but only to this one menu" concept, so `write` is
 * all-or-nothing, and denying it entirely is the only way to guarantee
 * "no breaking changes" at the RouterOS level itself. The one genuinely
 * safe, catalogued exception (changing the hotspot SSID) is deliberately
 * NOT done through that account -- updateSsid() below pushes it over the
 * existing, already-restricted mmsradius-api connection server-side,
 * scoped to one property on one object by this app's own code.
 *
 * Deliberately never added to StaffPermissions::ROUTE_PERMISSIONS, the
 * same precedent RouterProvisioningController's own routes already set --
 * tenant_admin/super_admin only.
 */
class RouterConsoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $routers = TenantAccess::scopeRouters(Router::query(), $request->user())
            ->with('shop')
            ->orderBy('name')
            ->get();

        return RouterResource::collection($routers);
    }

    /**
     * Returns the router's tunnel IP(s) plus the viewer account's
     * username/password and the fixed Winbox/SSH ports -- generating the
     * viewer credentials on first request for a router created before
     * this feature existed (Router::ensureViewerCredentials()). This is
     * one of the few places in the whole system that returns a raw
     * RouterOS password over the network, hence the audit log entry --
     * every reveal is logged, not just the first.
     */
    public function credentials(Request $request, Router $router): JsonResponse
    {
        $user = $request->user();
        TenantAccess::assertRouter($router, $user);

        $router->ensureViewerCredentials();

        app(SecurityActivityService::class)->log(
            $user,
            'router_remote_credentials_viewed',
            'Router remote-access (Winbox/SSH/WebFig) credentials viewed from the mobile app.',
            ['router_id' => $router->id, 'router_name' => $router->name],
            $request
        );

        return response()->json([
            'wireguard_ip' => $router->wireguard_internal_ip,
            'zerotier_ip' => $router->zerotier_ip,
            'winbox_port' => Router::VIEWER_WINBOX_PORT,
            'ssh_port' => Router::VIEWER_SSH_PORT,
            'username' => $router->viewer_username,
            'password' => $router->viewer_password,
            'note' => 'This login is read-only and cannot change the router. Your own device also needs network access to one of these IPs (e.g. your own WireGuard connection, or being on-site) -- this screen only supplies the login, not a connection.',
        ]);
    }

    public function updateSsid(Request $request, Router $router, RouterOsConnectionService $routerOs): JsonResponse
    {
        $user = $request->user();
        TenantAccess::assertRouter($router, $user);

        $validated = $request->validate([
            'ssid' => ['required', 'string', 'max:32'],
        ]);

        $result = $routerOs->applySsid($router, $validated['ssid']);

        return response()->json($result);
    }
}
