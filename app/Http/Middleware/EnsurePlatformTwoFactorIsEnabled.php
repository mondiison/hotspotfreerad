<?php

namespace App\Http\Middleware;

use App\Services\PlatformSecuritySettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user?->isSuperAdmin()
            && app(PlatformSecuritySettingsService::class)->requireSuperAdminTwoFactor()
            && ! $user->hasTwoFactorEnabled()
            && $request->routeIs('admin.*')
            && ! $request->routeIs('admin.profile.edit', 'admin.profile.update')
        ) {
            return redirect()
                ->route('admin.profile.edit')
                ->with('status', 'Platform policy requires two-factor authentication for super admins. Enable 2FA before continuing.');
        }

        return $next($request);
    }
}
