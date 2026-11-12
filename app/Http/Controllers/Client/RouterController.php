<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\RouterOsConnectionService;
use App\Support\ClientAccess;
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
}
