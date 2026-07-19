<?php

namespace App\Livewire\Admin;

use App\Models\SecurityActivity;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityActivitiesIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action_group = '';

    public string $tenant_id = '';

    public string $date_preset = '30';

    protected $queryString = [
        'search' => ['except' => ''],
        'action_group' => ['except' => ''],
        'tenant_id' => ['except' => ''],
        'date_preset' => ['except' => '30'],
    ];

    public function updated($property): void
    {
        if (in_array($property, ['search', 'action_group', 'tenant_id', 'date_preset'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'action_group', 'tenant_id']);
        $this->date_preset = '30';
        $this->resetPage();
    }

    public function render()
    {
        $this->validateFilters();

        $actor = auth()->user();
        $query = SecurityActivity::query()
            ->with(['tenant', 'user'])
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('tenant_id', $actor->tenant_id))
            ->when($actor->isSuperAdmin() && $this->tenant_id !== '', fn ($query) => $query->where('tenant_id', $this->tenant_id))
            ->when($this->action_group !== '', fn ($query) => $query->whereIn('action', $this->actionsForGroup($this->action_group)))
            ->when($this->date_preset !== 'all', fn ($query) => $query->where('created_at', '>=', now()->subDays((int) $this->date_preset)))
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('label', 'like', "%{$this->search}%")
                        ->orWhere('action', 'like', "%{$this->search}%")
                        ->orWhere('ip_address', 'like', "%{$this->search}%")
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', "%{$this->search}%")
                            ->orWhere('email', 'like', "%{$this->search}%"))
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('company_name', 'like', "%{$this->search}%"));
                });
            });

        return view('livewire.admin.security-activities-index', [
            'activities' => $query->latest()->paginate(20),
            'tenants' => $this->tenants(),
            'summary' => $this->summary(),
            'actionGroups' => $this->actionGroups(),
        ]);
    }

    private function summary(): array
    {
        $actor = auth()->user();
        $base = SecurityActivity::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('tenant_id', $actor->tenant_id));

        return [
            'total' => (clone $base)->count(),
            'sign_ins' => (clone $base)->whereIn('action', $this->actionsForGroup('sign_in'))->count(),
            'passkeys' => (clone $base)->whereIn('action', $this->actionsForGroup('passkey'))->count(),
            'passwords' => (clone $base)->whereIn('action', $this->actionsForGroup('password'))->count(),
        ];
    }

    private function tenants(): Collection
    {
        if (! auth()->user()->isSuperAdmin()) {
            return collect();
        }

        return Tenant::query()->orderBy('company_name')->get();
    }

    private function actionGroups(): array
    {
        return [
            'sign_in' => 'Sign-ins',
            'passkey' => 'Passkeys',
            'two_factor' => 'Two-factor',
            'password' => 'Passwords',
            'profile' => 'Profile',
            'session' => 'Sessions',
            'policy' => 'Policies',
        ];
    }

    private function actionsForGroup(string $group): array
    {
        return match ($group) {
            'sign_in' => ['login', 'two_factor_login', 'passkey_login', 'logout', 'tenant_inactive_login_blocked'],
            'passkey' => ['passkey_registered', 'passkey_login', 'passkey_deleted'],
            'two_factor' => ['two_factor_setup_started', 'two_factor_enabled', 'two_factor_disabled', 'two_factor_challenge_started', 'two_factor_challenge_failed', 'two_factor_login', 'recovery_codes_regenerated'],
            'password' => ['password_updated', 'managed_user_password_reset_sent'],
            'profile' => ['profile_updated', 'password_updated'],
            'session' => ['other_sessions_logged_out', 'logout'],
            'policy' => ['platform_security_updated', 'tenant_two_factor_policy_updated'],
            default => [],
        };
    }

    private function validateFilters(): void
    {
        validator([
            'action_group' => $this->action_group ?: null,
            'tenant_id' => $this->tenant_id ?: null,
            'date_preset' => $this->date_preset,
        ], [
            'action_group' => ['nullable', Rule::in(array_keys($this->actionGroups()))],
            'tenant_id' => ['nullable', Rule::exists('tenants', 'id')],
            'date_preset' => ['required', Rule::in(['7', '30', '90', '365', 'all'])],
        ])->validate();
    }
}
