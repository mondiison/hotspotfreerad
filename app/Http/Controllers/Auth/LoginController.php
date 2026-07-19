<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

    public function store(Request $request): RedirectResponse
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
            Auth::logout();

            return back()
                ->withErrors(['email' => 'This tenant workspace is not active.'])
                ->onlyInput('email');
        }

        if ($user->hasTwoFactorEnabled()) {
            Auth::logout();

            $request->session()->put([
                'login.two_factor_user_id' => $user->id,
                'login.remember' => $request->boolean('remember'),
            ]);

            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        $request->session()->forget('url.intended');

        return redirect()->route('redirect-after-login');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
