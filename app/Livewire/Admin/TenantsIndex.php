<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManagementService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class TenantsIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $billing_model = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingTenantId = null;

    public ?int $deletingTenantId = null;

    public string $company_name = '';

    public string $slug = '';

    public string $owner_email = '';

    public string $subscription_plan = 'basic';

    public string $form_billing_model = 'subscription';

    public string $commission_rate = '0';

    public string $trial_ends_at = '';

    public bool $is_active = true;

    public bool $require_two_factor = false;

    public bool $public_site_enabled = true;

    public string $brand_color = '#0f766e';

    public string $public_site_tagline = '';

    public string $public_site_about = '';

    public string $contact_phone = '';

    public string $contact_email = '';

    public string $contact_address = '';

    public ?string $savedMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'billing_model' => ['except' => ''],
    ];

    public function mount(TenantManagementService $tenants, array $filters = []): void
    {
        $tenants->assertSuperAdmin(auth()->user());

        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->billing_model = (string) ($filters['billing_model'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'billing_model'], true)) {
            $this->resetPage();
        }

        if ($property === 'form_billing_model' && $this->form_billing_model !== 'commission') {
            $this->commission_rate = '0';
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $tenantId, TenantManagementService $tenants): void
    {
        $tenants->assertSuperAdmin(auth()->user());

        $tenant = Tenant::findOrFail($tenantId);

        $this->editingTenantId = $tenant->id;
        $this->company_name = (string) $tenant->company_name;
        $this->slug = (string) $tenant->slug;
        $this->owner_email = (string) $tenant->owner_email;
        $this->subscription_plan = (string) ($tenant->subscription_plan ?: 'basic');
        $this->form_billing_model = (string) ($tenant->billing_model ?: 'subscription');
        $this->commission_rate = (string) ($tenant->commission_rate ?? '0');
        $this->trial_ends_at = $tenant->trial_ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->is_active = (bool) $tenant->is_active;
        $this->require_two_factor = (bool) $tenant->require_two_factor;
        $this->public_site_enabled = (bool) $tenant->public_site_enabled;
        $this->brand_color = (string) ($tenant->brand_color ?: '#0f766e');
        $this->public_site_tagline = (string) $tenant->public_site_tagline;
        $this->public_site_about = (string) $tenant->public_site_about;
        $this->contact_phone = (string) $tenant->contact_phone;
        $this->contact_email = (string) $tenant->contact_email;
        $this->contact_address = (string) $tenant->contact_address;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function save(TenantManagementService $tenants): void
    {
        $tenant = $this->editingTenantId ? Tenant::findOrFail($this->editingTenantId) : null;
        $data = Validator::make($this->formData(), $tenants->rules($tenant))->validate();

        if ($tenant) {
            $tenants->update($tenant, $data, auth()->user());
            $this->savedMessage = 'Tenant updated.';
        } else {
            $tenants->create($data, auth()->user());
            $this->savedMessage = 'Tenant created and temporary password sent to owner email.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function sendResetLink(int $tenantId, TenantManagementService $tenants): void
    {
        try {
            $this->savedMessage = $tenants->sendOwnerResetLink(Tenant::findOrFail($tenantId), auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('owner_email', $exception->errors()['owner_email'][0] ?? 'Unable to send reset link.');
        }
    }

    public function confirmDelete(int $tenantId, TenantManagementService $tenants): void
    {
        $tenants->assertSuperAdmin(auth()->user());

        $this->deletingTenantId = Tenant::findOrFail($tenantId)->id;
        $this->showDeleteModal = true;
    }

    public function delete(TenantManagementService $tenants): void
    {
        if (! $this->deletingTenantId) {
            return;
        }

        $tenants->delete(Tenant::findOrFail($this->deletingTenantId), auth()->user());

        $this->showDeleteModal = false;
        $this->deletingTenantId = null;
        $this->savedMessage = 'Tenant deleted.';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'billing_model']);
        $this->resetPage();
    }

    public function render()
    {
        $this->validateOnlyFilters();

        $tenants = Tenant::query()
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('company_name', 'like', "%{$this->search}%")
                        ->orWhere('slug', 'like', "%{$this->search}%")
                        ->orWhere('owner_email', 'like', "%{$this->search}%");
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($this->billing_model, fn ($query) => $query->where('billing_model', $this->billing_model))
            ->latest()
            ->paginate(15);

        return view('livewire.admin.tenants-index', [
            'tenants' => $tenants,
            'ownerUsers' => $this->ownerUsers($tenants->getCollection()->pluck('id'), $tenants->getCollection()->pluck('owner_email')),
            'deletingTenant' => $this->deletingTenantId ? Tenant::find($this->deletingTenantId) : null,
        ]);
    }

    private function ownerUsers($tenantIds, $ownerEmails)
    {
        return User::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('email', $ownerEmails)
            ->where('role', 'tenant_admin')
            ->get()
            ->keyBy('tenant_id');
    }

    private function formData(): array
    {
        return [
            'company_name' => $this->company_name,
            'slug' => $this->slug,
            'owner_email' => $this->owner_email,
            'subscription_plan' => $this->subscription_plan,
            'billing_model' => $this->form_billing_model,
            'commission_rate' => $this->commission_rate,
            'trial_ends_at' => $this->trial_ends_at,
            'is_active' => $this->is_active,
            'require_two_factor' => $this->require_two_factor,
            'public_site_enabled' => $this->public_site_enabled,
            'brand_color' => $this->brand_color,
            'public_site_tagline' => $this->public_site_tagline,
            'public_site_about' => $this->public_site_about,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'contact_address' => $this->contact_address,
        ];
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingTenantId',
            'company_name',
            'slug',
            'owner_email',
            'commission_rate',
            'trial_ends_at',
            'public_site_tagline',
            'public_site_about',
            'contact_phone',
            'contact_email',
            'contact_address',
        ]);
        $this->subscription_plan = 'basic';
        $this->form_billing_model = 'subscription';
        $this->commission_rate = '0';
        $this->is_active = true;
        $this->require_two_factor = false;
        $this->public_site_enabled = true;
        $this->brand_color = '#0f766e';
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'status' => $this->status ?: null,
            'billing_model' => $this->billing_model ?: null,
        ], [
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'billing_model' => ['nullable', Rule::in(['subscription', 'commission'])],
        ])->validate();
    }
}
