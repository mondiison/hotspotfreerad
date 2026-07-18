<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantAdminTemporaryPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        $tenants = Tenant::latest()->paginate(15);
        $ownerUsers = User::query()
            ->whereIn('tenant_id', $tenants->getCollection()->pluck('id'))
            ->whereIn('email', $tenants->getCollection()->pluck('owner_email'))
            ->where('role', 'tenant_admin')
            ->get()
            ->keyBy('tenant_id');

        return view('admin.tenants.index', [
            'ownerUsers' => $ownerUsers,
            'tenants' => $tenants,
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
        $password = Str::random(16);

        $tenantAdmin = DB::transaction(function () use ($data, $password): User {
            $tenant = Tenant::create($data);

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

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant created and temporary password sent to owner email.');
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

        DB::transaction(function () use ($tenant, $data): void {
            $previousOwnerEmail = $tenant->owner_email;
            $ownerUser = User::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', 'tenant_admin')
                ->where('email', $previousOwnerEmail)
                ->first();

            $tenant->update($data);

            if ($ownerUser) {
                $ownerUser->update([
                    'email' => $tenant->owner_email,
                    'is_active' => $tenant->is_active,
                ]);

                return;
            }
        });

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant updated.');
    }

    public function sendOwnerResetLink(Tenant $tenant): RedirectResponse
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

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
            return back()->withErrors(['owner_email' => 'The tenant owner login is inactive. Activate the tenant before sending a reset link.']);
        }

        Password::sendResetLink(['email' => $tenantAdmin->email]);

        return back()->with('status', 'Password reset link sent to '.$tenantAdmin->email.'.');
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

    private function ownerUserFor(Tenant $tenant): ?User
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'tenant_admin')
            ->where('email', $tenant->owner_email)
            ->first();
    }
}
