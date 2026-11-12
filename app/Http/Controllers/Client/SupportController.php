<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 2 (2026-11-13): deliberately a static FAQ + "contact your
 * provider" page, not a full support-ticket/notification system -- the
 * Client's provider IS the tenant admin who onboarded them, so "support"
 * here means reaching that tenant, not this platform's own support line
 * (the one hotspot customers reach on payment-failed.blade.php).
 */
class SupportController extends Controller
{
    public function index(Request $request): View
    {
        $client = $request->user()->client->load('tenant');

        return view('client.support.index', ['client' => $client]);
    }
}
