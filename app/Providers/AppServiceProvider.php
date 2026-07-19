<?php

namespace App\Providers;

use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use App\Services\SecurityActivityService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
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

        Event::listen(PasskeyRegistered::class, function (PasskeyRegistered $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            app(SecurityActivityService::class)->log(
                $event->user,
                'passkey_registered',
                'Passkey registered: '.$event->passkey->name.'.',
                [
                    'passkey_id' => $event->passkey->id,
                    'passkey_name' => $event->passkey->name,
                    'authenticator' => $event->passkey->authenticator,
                ]
            );
        });

        Event::listen(PasskeyVerified::class, function (PasskeyVerified $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            app(SecurityActivityService::class)->log(
                $event->user,
                'passkey_login',
                'Signed in with passkey: '.$event->passkey->name.'.',
                [
                    'passkey_id' => $event->passkey->id,
                    'passkey_name' => $event->passkey->name,
                    'authenticator' => $event->passkey->authenticator,
                ]
            );
        });
    }
}
