<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the client.* route group (sibling of AuthorizeTenantStaff, which
 * wraps admin.*) -- only a 'client'-role user may reach any client.* route
 * at all, and the single lapse-enforcement checkpoint lives here: once
 * hotspot:enforce-client-billing has flipped a Client's subscription past
 * active/trialing, this denies every client.* route outright rather than
 * each route having to remember to check it individually. A Client's real
 * hotspot/PPPoE/POS customers have no dependency on this at all -- nothing
 * here ever touches RADIUS/Subscription.
 */
class AuthorizeClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->isClient() && $user->client?->hasUsableBillingAccess(), 403);

        return $next($request);
    }
}
