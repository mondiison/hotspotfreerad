<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function rules(User $actor, ?User $managedUser = null): array
    {
        $roles = $actor->isSuperAdmin() ? ['super_admin', 'tenant_admin'] : ['tenant_admin'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($managedUser)],
            'tenant_id' => [$actor->isSuperAdmin() ? 'nullable' : 'required', Rule::exists('tenants', 'id')],
            'role' => ['required', Rule::in($roles)],
            'is_active' => ['nullable', 'boolean'],
            'password' => [$managedUser ? 'nullable' : 'required', 'string', 'min:8'],
        ];
    }

    public function validated(Request $request, ?User $managedUser = null): array
    {
        if (! $request->user()->isSuperAdmin()) {
            $request->merge([
                'tenant_id' => $request->user()->tenant_id,
                'role' => 'tenant_admin',
            ]);
        }

        return $this->normalize(
            $request->validate($this->rules($request->user(), $managedUser)) + ['is_active' => false],
            $request->user(),
            $managedUser
        );
    }

    public function create(array $data, User $actor): User
    {
        return User::create($this->normalize($data, $actor));
    }

    public function update(User $managedUser, array $data, User $actor): User
    {
        $this->assertCanManage($actor, $managedUser);

        $managedUser->update($this->normalize($data, $actor, $managedUser));

        return $managedUser;
    }

    public function delete(User $managedUser, User $actor): void
    {
        $this->assertCanManage($actor, $managedUser);

        if ($actor->is($managedUser)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account while signed in.',
            ]);
        }

        $managedUser->delete();
    }

    public function tenantOptions(User $user): Collection
    {
        return $user->isSuperAdmin()
            ? Tenant::orderBy('company_name')->get()
            : Tenant::whereKey($user->tenant_id)->get();
    }

    public function assertCanManage(User $actor, User $managedUser): void
    {
        abort_unless($actor->isSuperAdmin() || $managedUser->tenant_id === $actor->tenant_id, 403);
    }

    public function normalize(array $data, User $actor, ?User $managedUser = null): array
    {
        if (! $actor->isSuperAdmin()) {
            $data['tenant_id'] = $actor->tenant_id;
            $data['role'] = 'tenant_admin';
        }

        if (($data['role'] ?? null) === 'super_admin') {
            $data['tenant_id'] = null;
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        if ($managedUser && $actor->is($managedUser)) {
            $data['is_active'] = true;
        }

        return $data;
    }
}
