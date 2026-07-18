<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(): View
    {
        return view('admin.tenants.index', [
            'tenants' => Tenant::latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.tenants.form', ['tenant' => new Tenant()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Tenant::create($this->validated($request));

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant created.');
    }

    public function edit(Tenant $tenant): View
    {
        return view('admin.tenants.form', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update($this->validated($request, $tenant));

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant updated.');
    }

    public function destroy(Tenant $tenant): RedirectResponse
    {
        $tenant->delete();

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant deleted.');
    }

    private function validated(Request $request, ?Tenant $tenant = null): array
    {
        $tenantId = $tenant?->id ?? 'NULL';

        return $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', "unique:tenants,owner_email,{$tenantId}"],
            'subscription_plan' => ['required', 'string', 'max:50'],
            'trial_ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => false];
    }
}
