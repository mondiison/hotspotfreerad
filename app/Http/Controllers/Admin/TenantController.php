<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        Tenant::create($this->validated($request));

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

        $tenant->update($this->validated($request, $tenant));

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

        if (filled($data['slug'] ?? null)) {
            $data['slug'] = Str::slug($data['slug']);
        }

        return $data;
    }
}
