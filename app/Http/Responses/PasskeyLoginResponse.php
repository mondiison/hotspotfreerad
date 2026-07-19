<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse($request): Response
    {
        $redirect = redirect()->intended(config('passkeys.redirect', '/redirect-after-login'))->getTargetUrl();

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $redirect], 200);
        }

        return redirect()->to($redirect);
    }
}
