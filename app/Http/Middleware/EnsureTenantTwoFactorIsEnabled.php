<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->loadMissing('tenant');

        if (
            $user?->isTenantAdmin()
            && $user->tenant?->require_two_factor
            && ! $user->hasTwoFactorEnabled()
            && $request->routeIs('admin.*')
            && ! $request->routeIs('admin.profile.edit', 'admin.profile.update')
        ) {
            return redirect()
                ->route('admin.profile.edit')
                ->with('status', 'Your tenant requires two-factor authentication. Enable 2FA before continuing.');
        }

        return $next($request);
    }
}
