<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\ProfileService;
use App\Services\SecurityActivityService;
use App\Services\SessionSecurityService;
use App\Services\TwoFactorService;
use Livewire\Component;

class ProfileCard extends Component
{
    public User $user;

    public string $name = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $savedMessage = null;

    public string $two_factor_code = '';

    public string $two_factor_disable_password = '';

    public string $logout_other_sessions_password = '';

    public ?string $twoFactorSetupSecret = null;

    public ?string $twoFactorProvisioningUri = null;

    public array $plainRecoveryCodes = [];

    public function mount(): void
    {
        $this->user = auth()->user()->load('tenant');
        $this->name = $this->user->name;
    }

    public function save(ProfileService $profiles, SecurityActivityService $activity): void
    {
        $data = $this->validate($profiles->rules());
        $passwordChanged = filled($data['password'] ?? null);

        $this->user = $profiles->update($this->user, $data)->load('tenant');

        $this->reset([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        $this->savedMessage = 'Profile updated.';
        session()->flash('status', 'Profile updated.');
        $activity->log(
            $this->user,
            $passwordChanged ? 'password_updated' : 'profile_updated',
            $passwordChanged ? 'Password changed from profile.' : 'Profile details updated.'
        );
    }

    public function startTwoFactorSetup(TwoFactorService $twoFactor, SecurityActivityService $activity): void
    {
        $secret = $twoFactor->generateSecret();

        $this->user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->user->refresh();
        $this->twoFactorSetupSecret = $secret;
        $this->twoFactorProvisioningUri = $twoFactor->provisioningUri($this->user, $secret);
        $this->two_factor_code = '';
        $this->plainRecoveryCodes = [];
        $this->savedMessage = null;
        $activity->log($this->user, 'two_factor_setup_started', 'Two-factor setup started.');
    }

    public function confirmTwoFactor(TwoFactorService $twoFactor, SecurityActivityService $activity): void
    {
        $this->validate([
            'two_factor_code' => ['required', 'digits:6'],
        ]);

        if (! $this->user->two_factor_secret || ! $twoFactor->verifyCode($this->user->two_factor_secret, $this->two_factor_code)) {
            $this->addError('two_factor_code', 'The authentication code is invalid.');

            return;
        }

        $codes = $twoFactor->recoveryCodes();

        $this->user->forceFill([
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->user->refresh();
        $this->two_factor_code = '';
        $this->twoFactorSetupSecret = null;
        $this->twoFactorProvisioningUri = null;
        $this->plainRecoveryCodes = $codes;
        $this->savedMessage = 'Two-factor authentication is enabled.';
        session()->flash('status', 'Two-factor authentication is enabled.');
        $activity->log($this->user, 'two_factor_enabled', 'Two-factor authentication enabled.');
    }

    public function regenerateRecoveryCodes(TwoFactorService $twoFactor, SecurityActivityService $activity): void
    {
        abort_unless($this->user->hasTwoFactorEnabled(), 403);

        $codes = $twoFactor->recoveryCodes();

        $this->user->forceFill([
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
        ])->save();

        $this->user->refresh();
        $this->plainRecoveryCodes = $codes;
        $this->savedMessage = 'Recovery codes regenerated.';
        $activity->log($this->user, 'recovery_codes_regenerated', 'Two-factor recovery codes regenerated.');
    }

    public function disableTwoFactor(ProfileService $profiles, SecurityActivityService $activity): void
    {
        $this->validate([
            'two_factor_disable_password' => $profiles->currentPasswordRule(),
        ]);

        $this->user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->user->refresh();
        $this->reset([
            'two_factor_disable_password',
            'two_factor_code',
            'twoFactorSetupSecret',
            'twoFactorProvisioningUri',
            'plainRecoveryCodes',
        ]);
        $this->savedMessage = 'Two-factor authentication is disabled.';
        session()->flash('status', 'Two-factor authentication is disabled.');
        $activity->log($this->user, 'two_factor_disabled', 'Two-factor authentication disabled.');
    }

    public function logoutOtherSessions(ProfileService $profiles, SessionSecurityService $sessions, SecurityActivityService $activity): void
    {
        $this->validate([
            'logout_other_sessions_password' => $profiles->currentPasswordRule(),
        ]);

        $count = $sessions->logoutOtherSessions($this->user, session()->getId());

        $this->logout_other_sessions_password = '';
        $this->savedMessage = $count === 1
            ? 'Signed out 1 other session.'
            : 'Signed out '.$count.' other sessions.';
        session()->flash('status', $this->savedMessage);
        $activity->log($this->user, 'other_sessions_logged_out', $this->savedMessage, [
            'session_count' => $count,
        ]);
    }

    public function render(SessionSecurityService $sessions, SecurityActivityService $activity)
    {
        return view('livewire.admin.profile-card', [
            'activeSessions' => $sessions->sessionsFor($this->user, session()->getId()),
            'securityActivities' => $activity->recentFor($this->user),
            'sessionDriverSupported' => config('session.driver') === 'database',
        ]);
    }
}
