<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantAdminTemporaryPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantManagementService
{
    public function rules(?Tenant $tenant = null): array
    {
        $ownerUserId = $tenant
            ? $this->ownerUserFor($tenant)?->id
            : null;

        return [
            'company_name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::notIn(['admin', 'api', 'hotspot', 'login', 'logout', 'storage', 'build']),
                Rule::unique('tenants', 'slug')->ignore($tenant?->id),
            ],
            'owner_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('tenants', 'owner_email')->ignore($tenant?->id),
                Rule::unique('users', 'email')->ignore($ownerUserId),
            ],
            'subscription_plan' => ['required', 'string', 'max:50'],
            'billing_model' => ['nullable', 'string', Rule::in(['subscription', 'commission'])],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trial_ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'require_two_factor' => ['nullable', 'boolean'],
            'public_site_enabled' => ['nullable', 'boolean'],
            'brand_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'public_site_tagline' => ['nullable', 'string', 'max:255'],
            'public_site_about' => ['nullable', 'string', 'max:2000'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function validated(Request $request, ?Tenant $tenant = null): array
    {
        $this->assertSuperAdmin($request->user());

        return $this->normalize($request->validate($this->rules($tenant)) + [
            'is_active' => false,
            'require_two_factor' => false,
            'public_site_enabled' => false,
            'brand_color' => '#0f766e',
            'billing_model' => 'subscription',
            'commission_rate' => 0,
        ]);
    }

    public function create(array $data, User $actor): Tenant
    {
        $this->assertSuperAdmin($actor);
        $password = Str::random(16);

        $tenantAdmin = DB::transaction(function () use ($data, $password): User {
            $tenant = Tenant::create($this->normalize($data));

            return User::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->company_name.' Admin',
                'email' => $tenant->owner_email,
                'role' => 'tenant_admin',
                'is_active' => $tenant->is_active,
                'must_change_password' => true,
                'password' => $password,
            ]);
        });

        $tenantAdmin->notify(new TenantAdminTemporaryPassword($tenantAdmin->tenant, $password));

        return $tenantAdmin->tenant;
    }

    public function update(Tenant $tenant, array $data, User $actor): Tenant
    {
        $this->assertSuperAdmin($actor);
        $normalized = $this->normalize($data);
        $previousRequireTwoFactor = (bool) $tenant->require_two_factor;

        DB::transaction(function () use ($tenant, $normalized): void {
            $ownerUser = $this->ownerUserFor($tenant);

            $tenant->update($normalized);

            if ($ownerUser) {
                $ownerUser->update([
                    'email' => $tenant->owner_email,
                    'is_active' => $tenant->is_active,
                ]);
            }
        });

        if ($previousRequireTwoFactor !== (bool) $tenant->require_two_factor) {
            app(SecurityActivityService::class)->log(
                $actor,
                'tenant_two_factor_policy_updated',
                'Tenant two-factor policy updated.',
                [
                    'tenant_id' => $tenant->id,
                    'tenant' => $tenant->company_name,
                    'owner_email' => $tenant->owner_email,
                    'required' => (bool) $tenant->require_two_factor,
                ]
            );
        }

        return $tenant;
    }

    public function delete(Tenant $tenant, User $actor): void
    {
        $this->assertSuperAdmin($actor);

        $tenant->delete();
    }

    public function sendOwnerResetLink(Tenant $tenant, User $actor): string
    {
        $this->assertSuperAdmin($actor);

        $tenantAdmin = $this->ownerUserFor($tenant)
            ?? User::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->company_name.' Admin',
                'email' => $tenant->owner_email,
                'role' => 'tenant_admin',
                'is_active' => $tenant->is_active,
                'must_change_password' => true,
                'password' => Str::random(32),
            ]);

        if (! $tenantAdmin->is_active || ! $tenant->is_active) {
            throw ValidationException::withMessages([
                'owner_email' => 'The tenant owner login is inactive. Activate the tenant before sending a reset link.',
            ]);
        }

        $status = Password::sendResetLink(['email' => $tenantAdmin->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'owner_email' => __($status),
            ]);
        }

        return 'Password reset link sent to '.$tenantAdmin->email.'.';
    }

    public function ownerUserFor(Tenant $tenant): ?User
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'tenant_admin')
            ->where('email', $tenant->owner_email)
            ->first();
    }

    public function normalize(array $data): array
    {
        $data['slug'] = filled($data['slug'] ?? null) ? Str::slug($data['slug']) : null;
        $data['billing_model'] = $data['billing_model'] ?? 'subscription';
        $data['commission_rate'] = ($data['billing_model'] ?? 'subscription') === 'commission'
            ? round((float) ($data['commission_rate'] ?? 0), 2)
            : 0;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['require_two_factor'] = (bool) ($data['require_two_factor'] ?? false);
        $data['public_site_enabled'] = (bool) ($data['public_site_enabled'] ?? false);
        $data['brand_color'] = $data['brand_color'] ?? '#0f766e';

        foreach (['public_site_tagline', 'public_site_about', 'contact_phone', 'contact_email', 'contact_address', 'trial_ends_at'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? $data[$field] : null;
        }

        return $data;
    }

    public function assertSuperAdmin(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 403);
    }
}
