<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'filters' => $request->only(['search', 'role', 'status']),
        ]);
    }

    public function create(Request $request, UserManagementService $users): View
    {
        return view('admin.users.form', [
            'managedUser' => new User,
            'tenants' => $users->tenantOptions($request->user()),
        ]);
    }

    public function store(Request $request, UserManagementService $users): RedirectResponse
    {
        $users->create($users->validated($request), $request->user());

        return redirect()->route('admin.users.index')->with('status', 'User created.');
    }

    public function edit(Request $request, User $user, UserManagementService $users): View
    {
        $users->assertCanManage($request->user(), $user);

        return view('admin.users.form', [
            'managedUser' => $user,
            'tenants' => $users->tenantOptions($request->user()),
        ]);
    }

    public function update(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $users->update($user, $users->validated($request, $user), $request->user());

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $users->delete($user, $request->user());

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }
}
