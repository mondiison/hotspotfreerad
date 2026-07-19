<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SecurityActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, SecurityActivityService $activity): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'The provided credentials do not match an active user.'])
                ->onlyInput('email');
        }

        $user = Auth::user()->load('tenant');

        if ($user->isTenantAdmin() && (! $user->tenant || ! $user->tenant->is_active)) {
            $activity->log($user, 'tenant_inactive_login_blocked', 'Login blocked because tenant is inactive.', request: $request);

            Auth::logout();

            return back()
                ->withErrors(['email' => 'This tenant workspace is not active.'])
                ->onlyInput('email');
        }

        if ($user->hasTwoFactorEnabled()) {
            $activity->log($user, 'two_factor_challenge_started', 'Password accepted. Two-factor challenge required.', request: $request);

            Auth::logout();

            $request->session()->put([
                'login.two_factor_user_id' => $user->id,
                'login.remember' => $request->boolean('remember'),
            ]);

            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        $request->session()->forget('url.intended');

        $activity->log($user, 'login', 'Signed in successfully.', request: $request);

        return redirect()->route('redirect-after-login');
    }

    public function destroy(Request $request, SecurityActivityService $activity): RedirectResponse
    {
        if ($request->user()) {
            $activity->log($request->user(), 'logout', 'Signed out.', request: $request);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
