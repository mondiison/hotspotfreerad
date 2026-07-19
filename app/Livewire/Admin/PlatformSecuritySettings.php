<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\PlatformSecuritySettingsService;
use App\Services\SecurityActivityService;
use Livewire\Component;

class PlatformSecuritySettings extends Component
{
    public bool $require_super_admin_two_factor = false;

    public ?string $savedMessage = null;

    public function mount(PlatformSecuritySettingsService $settings): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $this->require_super_admin_two_factor = $settings->requireSuperAdminTwoFactor();
    }

    public function save(PlatformSecuritySettingsService $settings, SecurityActivityService $activity): void
    {
        $settings->update([
            'require_super_admin_two_factor' => $this->require_super_admin_two_factor,
        ], auth()->user());

        $this->savedMessage = 'Platform security settings updated.';
        session()->flash('status', $this->savedMessage);

        $activity->log(auth()->user(), 'platform_security_updated', 'Platform security settings updated.', [
            'require_super_admin_two_factor' => $this->require_super_admin_two_factor,
        ]);
    }

    public function render()
    {
        $superAdmins = User::query()
            ->where('role', 'super_admin')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.platform-security-settings', [
            'superAdmins' => $superAdmins,
            'superAdminCount' => $superAdmins->count(),
            'superAdminsWithTwoFactor' => $superAdmins->filter->hasTwoFactorEnabled()->count(),
            'superAdminsMissingTwoFactor' => $superAdmins->reject->hasTwoFactorEnabled()->count(),
        ]);
    }
}
