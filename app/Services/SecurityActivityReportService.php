<?php

namespace App\Services;

use App\Models\SecurityActivity;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SecurityActivityReportService
{
    public function datePresets(): array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '365' => 'Last 12 months',
            'all' => 'All time',
        ];
    }

    public function actionGroups(): array
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

    public function filters(array $input): array
    {
        $data = Validator::make($input, [
            'search' => ['nullable', 'string', 'max:255'],
            'action_group' => ['nullable', Rule::in(array_keys($this->actionGroups()))],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'date_preset' => ['nullable', Rule::in(array_keys($this->datePresets()))],
        ])->validate();

        return [
            'search' => $data['search'] ?? '',
            'action_group' => $data['action_group'] ?? '',
            'tenant_id' => (string) ($data['tenant_id'] ?? ''),
            'date_preset' => $data['date_preset'] ?? '30',
        ];
    }

    public function query(User $user, array $filters)
    {
        return SecurityActivity::query()
            ->with(['tenant', 'user'])
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('tenant_id', $user->tenant_id))
            ->when($user->isSuperAdmin() && $filters['tenant_id'] !== '', fn ($query) => $query->where('tenant_id', $filters['tenant_id']))
            ->when($filters['action_group'] !== '', fn ($query) => $query->whereIn('action', $this->actionsForGroup($filters['action_group'])))
            ->when($filters['date_preset'] !== 'all', fn ($query) => $query->where('created_at', '>=', now()->subDays((int) $filters['date_preset'])))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('label', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('company_name', 'like', "%{$search}%"));
                });
            });
    }

    public function summary(User $user): array
    {
        $base = SecurityActivity::query()
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('tenant_id', $user->tenant_id));

        return [
            'total' => (clone $base)->count(),
            'sign_ins' => (clone $base)->whereIn('action', $this->actionsForGroup('sign_in'))->count(),
            'passkeys' => (clone $base)->whereIn('action', $this->actionsForGroup('passkey'))->count(),
            'passwords' => (clone $base)->whereIn('action', $this->actionsForGroup('password'))->count(),
        ];
    }

    public function queryParams(array $filters): array
    {
        return array_filter([
            'search' => $filters['search'],
            'action_group' => $filters['action_group'],
            'tenant_id' => $filters['tenant_id'],
            'date_preset' => $filters['date_preset'] === '30' ? null : $filters['date_preset'],
        ], fn ($value) => filled($value));
    }

    public function groupLabel(?string $group): string
    {
        return $group ? ($this->actionGroups()[$group] ?? 'All') : 'All';
    }

    public function dateLabel(string $preset): string
    {
        return $this->datePresets()[$preset] ?? 'Last 30 days';
    }

    public function actionsForGroup(string $group): array
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
}
