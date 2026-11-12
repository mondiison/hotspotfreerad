<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Services\SecurityActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The mobile app's own login/logout -- deliberately simpler than the web
 * LoginController it mirrors: no "remember me" (a mobile bearer token is
 * already long-lived by design), and two-factor-enabled accounts are
 * REJECTED outright for now rather than silently bypassed, which would be
 * a real security regression for any user who enabled 2FA specifically
 * because their account needs it. A proper mobile 2FA challenge is a
 * later phase, not guessed at here.
 */
class AuthController extends Controller
{
    public function login(Request $request, SecurityActivityService $activity): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        if (! Auth::once([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ])) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match an active user.',
            ]);
        }

        $user = Auth::user()->load('tenant');

        if ($user->isTenantAdmin() && (! $user->tenant || ! $user->tenant->is_active)) {
            $activity->log($user, 'tenant_inactive_login_blocked', 'Mobile login blocked because tenant is inactive.', request: $request);

            throw ValidationException::withMessages([
                'email' => 'This tenant workspace is not active.',
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'email' => 'Two-factor authentication is not yet supported on the mobile app. Please use the web admin to sign in.',
            ]);
        }

        // Re-logging in on the same phone (reinstall, cleared app storage)
        // replaces that device's own token rather than accumulating a new
        // one every time -- device_name is a free-text label the app
        // chooses once and keeps (e.g. the device model), not something a
        // user types.
        $user->tokens()->where('name', $credentials['device_name'])->delete();
        $token = $user->createToken($credentials['device_name']);

        $activity->log($user, 'api_login', 'Signed in from the mobile app.', ['device_name' => $credentials['device_name']], $request);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request, SecurityActivityService $activity): JsonResponse
    {
        $activity->log($request->user(), 'api_logout', 'Signed out of the mobile app.', request: $request);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
