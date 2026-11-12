<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\RouterOsConnectionService;
use App\Support\ClientAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The read-only "already-provisioned router, CPU/RAM/uptime only" view a
 * Client is allowed to see -- reuses the exact same RouterOsConnectionService
 * calls RouterInsight already makes (no new RouterOS interaction), but none
 * of the other tabs (scripts, credentials, console, port map, network
 * settings) a Client must never reach. A Client can never add/remove a
 * router -- there is deliberately no create/store/destroy here at all.
 */
class RouterController extends Controller
{
    public function show(Request $request, Router $router, RouterOsConnectionService $routerOs): View
    {
        ClientAccess::assertRouter($router, $request->user());

        $resource = $routerOs->systemResource($router);
        $health = $routerOs->systemHealth($router);

        return view('client.router', [
            'router' => $router->load('shop'),
            'liveResource' => $resource['success'] ? $resource : null,
            'liveError' => $resource['success'] ? null : $resource['error'],
            'health' => $health['success'] ? $health['fields'] : [],
        ]);
    }

    /**
     * Phase 2 (2026-11-13): the one narrow write a Client may make to a
     * router at all -- the Wi-Fi name shown to their own customers (see
     * CLAUDE.md's "self-service connect flyer" entries: hotspot_ssid is
     * already read by that flyer as customer-facing information, not a
     * trade secret the way scripts/credentials are). A direct merge into
     * provisioning_settings, not RouterManagementService::update(), since
     * that method's normalize()/port-derivation pipeline is built for the
     * full technical wizard this route must never expose -- here we only
     * ever touch this one key, leaving every other setting untouched.
     */
    public function updateSsid(Request $request, Router $router): RedirectResponse
    {
        ClientAccess::assertRouter($router, $request->user());

        $data = $request->validate([
            'hotspot_ssid' => ['required', 'string', 'max:32'],
        ]);

        $router->update([
            'provisioning_settings' => array_merge($router->provisioning_settings ?? [], [
                'hotspot_ssid' => $data['hotspot_ssid'],
            ]),
        ]);

        return redirect()->route('client.routers.show', $router)->with('status', 'Wi-Fi name updated. Re-provisioning (handled by your provider) will apply it to the router.');
    }
}
