<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedirectAfterLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user()->load('tenant');

        if ($user->must_change_password) {
            return redirect()->route('password.force-change');
        }

        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if (! $user->tenant || ! $user->tenant->is_active) {
            abort(403, 'No active tenant assigned to this account.');
        }

        return redirect()->route('admin.dashboard');
    }
}
