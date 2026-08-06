<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to the admin.* route group only. Super admins and tenant admins
 * are unaffected; a tenant_staff user is blocked (403) from any admin route
 * not explicitly allowed for their permissions in StaffPermissions.
 */
class AuthorizeTenantStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->canAccessRoute($request->route()?->getName())) {
            abort(403);
        }

        return $next($request);
    }
}
