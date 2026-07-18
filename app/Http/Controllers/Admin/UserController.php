<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $query = User::query()
            ->with('tenant')
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('tenant_id', $user->tenant_id))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('company_name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status')->toString() === 'active'));

        return view('admin.users.index', [
            'users' => $query->latest()->paginate(15)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.users.form', [
            'managedUser' => new User(),
            'tenants' => $this->tenantOptions($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        User::create($data);

        return redirect()->route('admin.users.index')->with('status', 'User created.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->assertCanManage($request->user(), $user);

        return view('admin.users.form', [
            'managedUser' => $user,
            'tenants' => $this->tenantOptions($request->user()),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->assertCanManage($request->user(), $user);
        $data = $this->validated($request, $user);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($request->user()->is($user)) {
            $data['is_active'] = true;
        }

        $user->update($data);

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->assertCanManage($request->user(), $user);

        if ($request->user()->is($user)) {
            return back()->withErrors(['user' => 'You cannot delete your own account while signed in.']);
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }

    private function validated(Request $request, ?User $managedUser = null): array
    {
        $actor = $request->user();
        $managedUserId = $managedUser?->id ?? 'NULL';
        $roles = $actor->isSuperAdmin() ? ['super_admin', 'tenant_admin'] : ['tenant_admin'];

        if (! $actor->isSuperAdmin()) {
            $request->merge([
                'tenant_id' => $actor->tenant_id,
                'role' => 'tenant_admin',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', "unique:users,email,{$managedUserId}"],
            'tenant_id' => [$actor->isSuperAdmin() ? 'nullable' : 'required', Rule::exists('tenants', 'id')],
            'role' => ['required', Rule::in($roles)],
            'is_active' => ['nullable', 'boolean'],
            'password' => [$managedUser ? 'nullable' : 'required', 'string', 'min:8'],
        ]) + ['is_active' => false];

        if (! $actor->isSuperAdmin()) {
            $data['tenant_id'] = $actor->tenant_id;
            $data['role'] = 'tenant_admin';
        }

        if (($data['role'] ?? null) === 'super_admin') {
            $data['tenant_id'] = null;
        }

        return $data;
    }

    private function tenantOptions(User $user)
    {
        return $user->isSuperAdmin()
            ? Tenant::orderBy('company_name')->get()
            : Tenant::whereKey($user->tenant_id)->get();
    }

    private function assertCanManage(User $actor, User $managedUser): void
    {
        abort_unless($actor->isSuperAdmin() || $managedUser->tenant_id === $actor->tenant_id, 403);
    }
}
