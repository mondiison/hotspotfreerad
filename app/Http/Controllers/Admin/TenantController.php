<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        return view('admin.tenants.index', [
            'tenants' => Tenant::latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        return view('admin.tenants.form', ['tenant' => new Tenant()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $this->validated($request);
        $password = $data['owner_password'];
        unset($data['owner_password'], $data['owner_password_confirmation']);

        DB::transaction(function () use ($data, $password): void {
            $tenant = Tenant::create($data);

            User::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->company_name.' Admin',
                'email' => $tenant->owner_email,
                'role' => 'tenant_admin',
                'is_active' => $tenant->is_active,
                'password' => $password,
            ]);
        });

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant created.');
    }

    public function edit(Tenant $tenant): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        return view('admin.tenants.form', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $this->validated($request, $tenant);
        $password = $data['owner_password'] ?? null;
        unset($data['owner_password'], $data['owner_password_confirmation']);

        DB::transaction(function () use ($tenant, $data, $password): void {
            $previousOwnerEmail = $tenant->owner_email;
            $ownerUser = User::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', 'tenant_admin')
                ->where('email', $previousOwnerEmail)
                ->first();

            $tenant->update($data);

            if ($ownerUser) {
                $ownerUser->update(array_filter([
                    'email' => $tenant->owner_email,
                    'is_active' => $tenant->is_active,
                    'password' => $password,
                ], fn ($value) => filled($value) || is_bool($value)));

                return;
            }

            if (filled($password)) {
                User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->company_name.' Admin',
                    'email' => $tenant->owner_email,
                    'role' => 'tenant_admin',
                    'is_active' => $tenant->is_active,
                    'password' => $password,
                ]);
            }
        });

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant updated.');
    }

    public function destroy(Tenant $tenant): RedirectResponse
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        $tenant->delete();

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant deleted.');
    }

    private function validated(Request $request, ?Tenant $tenant = null): array
    {
        $tenantId = $tenant?->id ?? 'NULL';
        $ownerUserId = $tenant
            ? User::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', 'tenant_admin')
                ->where('email', $tenant->owner_email)
                ->value('id')
            : null;

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::notIn(['admin', 'api', 'hotspot', 'login', 'logout', 'storage', 'build']),
                Rule::unique('tenants', 'slug')->ignore($tenant?->id),
            ],
            'owner_email' => ['required', 'email', 'max:255', "unique:tenants,owner_email,{$tenantId}"],
            'owner_password' => [$tenant ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'subscription_plan' => ['required', 'string', 'max:50'],
            'trial_ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'public_site_enabled' => ['nullable', 'boolean'],
            'brand_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'public_site_tagline' => ['nullable', 'string', 'max:255'],
            'public_site_about' => ['nullable', 'string', 'max:2000'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_address' => ['nullable', 'string', 'max:1000'],
        ]) + [
            'is_active' => false,
            'public_site_enabled' => false,
            'brand_color' => '#0f766e',
        ];

        validator($data, [
            'owner_email' => [
                Rule::unique('users', 'email')->ignore($ownerUserId),
            ],
        ])->validate();

        if (filled($data['slug'] ?? null)) {
            $data['slug'] = Str::slug($data['slug']);
        }

        return $data;
    }
}
