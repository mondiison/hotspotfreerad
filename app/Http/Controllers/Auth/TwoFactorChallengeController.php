<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.two_factor_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = User::query()->find($request->session()->get('login.two_factor_user_id'));

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('login');
        }

        $validCode = filled($data['code'] ?? null)
            && $twoFactor->verifyCode($user->two_factor_secret, $data['code']);
        $validRecoveryCode = filled($data['recovery_code'] ?? null)
            && $twoFactor->verifyRecoveryCode($user, $data['recovery_code']);

        if (! $validCode && ! $validRecoveryCode) {
            throw ValidationException::withMessages([
                'code' => 'The authentication code is invalid.',
            ]);
        }

        Auth::login($user, (bool) $request->session()->get('login.remember', false));

        $request->session()->regenerate();
        $request->session()->forget([
            'login.two_factor_user_id',
            'login.remember',
            'url.intended',
        ]);

        return redirect()->route('redirect-after-login');
    }
}
