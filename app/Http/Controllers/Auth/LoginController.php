<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(?Tenant $tenant = null): View
    {
        abort_if($tenant && (! $tenant->is_active || ! $tenant->public_site_enabled), 404);

        return view('auth.login', compact('tenant'));
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_slug' => ['nullable', 'string'],
        ]);

        $tenant = filled($credentials['tenant_slug'] ?? null)
            ? Tenant::where('slug', $credentials['tenant_slug'])->first()
            : null;

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'The provided credentials do not match an active user.'])
                ->onlyInput('email');
        }

        if ($tenant && ! Auth::user()->isSuperAdmin() && Auth::user()->tenant_id !== $tenant->id) {
            Auth::logout();

            return back()
                ->withErrors(['email' => 'This user does not belong to this tenant workspace.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
