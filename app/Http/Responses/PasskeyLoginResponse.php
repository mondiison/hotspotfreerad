<?php

namespace App\Http\Responses;

use App\Services\SecurityActivityService;
use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->user()) {
            app(SecurityActivityService::class)->log(
                $request->user(),
                'passkey_login',
                'Signed in with a passkey.',
                request: $request
            );
        }

        $redirect = redirect()->intended(config('passkeys.redirect', '/redirect-after-login'))->getTargetUrl();

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $redirect], 200);
        }

        return redirect()->to($redirect);
    }
}
