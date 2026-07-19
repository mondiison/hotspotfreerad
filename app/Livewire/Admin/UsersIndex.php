<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\SecurityActivityService;
use App\Services\UserManagementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class UsersIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $role = '';

    public string $status = '';

    public string $passkey_status = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingUserId = null;

    public ?int $deletingUserId = null;

    public string $tenant_id = '';

    public string $name = '';

    public string $email = '';

    public string $user_role = 'tenant_admin';

    public string $password = '';

    public bool $is_active = true;

    public ?string $savedMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'role' => ['except' => ''],
        'status' => ['except' => ''],
        'passkey_status' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->search = (string) ($filters['search'] ?? '');
        $this->role = (string) ($filters['role'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->passkey_status = (string) ($filters['passkey_status'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'role', 'status', 'passkey_status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->tenant_id = (string) (auth()->user()->isSuperAdmin() ? '' : auth()->user()->tenant_id);
        $this->user_role = 'tenant_admin';
        $this->showFormModal = true;
    }

    public function edit(int $userId, UserManagementService $users): void
    {
        $managedUser = User::findOrFail($userId);
        $users->assertCanManage(auth()->user(), $managedUser);

        $this->editingUserId = $managedUser->id;
        $this->tenant_id = (string) $managedUser->tenant_id;
        $this->name = (string) $managedUser->name;
        $this->email = (string) $managedUser->email;
        $this->user_role = (string) $managedUser->role;
        $this->password = '';
        $this->is_active = (bool) $managedUser->is_active;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function save(UserManagementService $users): void
    {
        $actor = auth()->user();
        $managedUser = $this->editingUserId ? User::findOrFail($this->editingUserId) : null;

        $data = Validator::make([
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->user_role,
            'password' => $this->password,
            'is_active' => $this->is_active,
        ], $users->rules($actor, $managedUser))->validate();

        if ($managedUser) {
            $users->update($managedUser, $data, $actor);
            $this->savedMessage = 'User updated.';
        } else {
            $users->create($data, $actor);
            $this->savedMessage = 'User created.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $userId, UserManagementService $users): void
    {
        $managedUser = User::findOrFail($userId);
        $users->assertCanManage(auth()->user(), $managedUser);

        $this->deletingUserId = $managedUser->id;
        $this->showDeleteModal = true;
    }

    public function sendPasswordResetLink(int $userId, UserManagementService $users, SecurityActivityService $activity): void
    {
        $managedUser = User::findOrFail($userId);
        $users->assertCanManage(auth()->user(), $managedUser);

        $status = Password::sendResetLink([
            'email' => $managedUser->email,
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            $this->addError('user', 'Unable to send a password reset link right now.');

            return;
        }

        $this->savedMessage = 'Password reset link sent to '.$managedUser->email.'.';

        $activity->log(auth()->user(), 'managed_user_password_reset_sent', 'Password reset link sent to managed user.', [
            'managed_user_id' => $managedUser->id,
            'managed_user_email' => $managedUser->email,
        ]);
    }

    public function delete(UserManagementService $users): void
    {
        if (! $this->deletingUserId) {
            return;
        }

        try {
            $users->delete(User::findOrFail($this->deletingUserId), auth()->user());
        } catch (ValidationException $exception) {
            $this->showDeleteModal = false;
            $this->addError('user', $exception->errors()['user'][0] ?? 'Unable to delete this user.');

            return;
        }

        $this->showDeleteModal = false;
        $this->deletingUserId = null;
        $this->savedMessage = 'User deleted.';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'role', 'status', 'passkey_status']);
        $this->resetPage();
    }

    public function render(UserManagementService $users)
    {
        $this->validateOnlyFilters();

        $actor = auth()->user();

        $adminUsers = User::query()
            ->with('tenant')
            ->withCount('passkeys')
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('tenant_id', $actor->tenant_id))
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('company_name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->role, fn ($query) => $query->where('role', $this->role))
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($this->passkey_status === 'registered', fn ($query) => $query->has('passkeys'))
            ->when($this->passkey_status === 'missing', fn ($query) => $query->doesntHave('passkeys'))
            ->latest()
            ->paginate(15);

        return view('livewire.admin.users-index', [
            'users' => $adminUsers,
            'tenants' => $this->tenants($users),
            'deletingUser' => $this->deletingUserId ? User::find($this->deletingUserId) : null,
        ]);
    }

    private function tenants(UserManagementService $users): Collection
    {
        return $users->tenantOptions(auth()->user());
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingUserId',
            'tenant_id',
            'name',
            'email',
            'password',
        ]);
        $this->user_role = 'tenant_admin';
        $this->is_active = true;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'role' => $this->role ?: null,
            'status' => $this->status ?: null,
            'passkey_status' => $this->passkey_status ?: null,
        ], [
            'role' => ['nullable', Rule::in(['super_admin', 'tenant_admin'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'passkey_status' => ['nullable', Rule::in(['registered', 'missing'])],
        ])->validate();
    }
}
