<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ForcePasswordChangeController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->must_change_password, 404);

        return view('auth.force-password-change');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->must_change_password, 404);

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        return redirect()->route('admin.dashboard')->with('status', 'Password updated.');
    }
}
