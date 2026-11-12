<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Client's own bare-minimum landing page (Phase 1 scope: no self-service
 * packages/vouchers/branding UI yet -- see CLAUDE.md). Shows their shops
 * (read-only names, no technical detail) and current billing status.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $client = $request->user()->client->load(['shops.routers', 'currentBillingSubscription.clientBillingPlan']);

        return view('client.dashboard', [
            'client' => $client,
        ]);
    }
}
