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

        $request->session()->regenerate();

        return redirect()->intended(
            $user->isTenantAdmin()
                ? route('tenant.public-site', $user->tenant)
                : route('admin.dashboard')
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
