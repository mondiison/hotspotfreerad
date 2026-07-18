<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->user()?->must_change_password
            && ! $request->routeIs('password.force-change', 'password.force-update', 'logout')
        ) {
            return redirect()->route('password.force-change');
        }

        return $next($request);
    }
}
