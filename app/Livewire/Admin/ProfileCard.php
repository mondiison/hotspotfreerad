<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\ProfileService;
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

    public ?string $twoFactorSetupSecret = null;

    public ?string $twoFactorProvisioningUri = null;

    public array $plainRecoveryCodes = [];

    public function mount(): void
    {
        $this->user = auth()->user()->load('tenant');
        $this->name = $this->user->name;
    }

    public function save(ProfileService $profiles): void
    {
        $data = $this->validate($profiles->rules());

        $this->user = $profiles->update($this->user, $data)->load('tenant');

        $this->reset([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        $this->savedMessage = 'Profile updated.';
        session()->flash('status', 'Profile updated.');
    }

    public function startTwoFactorSetup(TwoFactorService $twoFactor): void
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
    }

    public function confirmTwoFactor(TwoFactorService $twoFactor): void
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
    }

    public function regenerateRecoveryCodes(TwoFactorService $twoFactor): void
    {
        abort_unless($this->user->hasTwoFactorEnabled(), 403);

        $codes = $twoFactor->recoveryCodes();

        $this->user->forceFill([
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
        ])->save();

        $this->user->refresh();
        $this->plainRecoveryCodes = $codes;
        $this->savedMessage = 'Recovery codes regenerated.';
    }

    public function disableTwoFactor(ProfileService $profiles): void
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
    }

    public function render()
    {
        return view('livewire.admin.profile-card');
    }
}
