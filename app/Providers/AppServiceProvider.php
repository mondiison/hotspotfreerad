<?php

namespace App\Providers;

use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passkeys::authorizeLoginUsing(function ($request, User $user, Passkey $passkey): bool {
            if (! $user->is_active) {
                return false;
            }

            $user->loadMissing('tenant');

            return ! $user->isTenantAdmin() || (bool) $user->tenant?->is_active;
        });
    }
}
