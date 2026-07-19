<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        return view('admin.tenants.index', [
            'filters' => request()->only(['search', 'status', 'billing_model']),
        ]);
    }

    public function create(): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        return view('admin.tenants.form', ['tenant' => new Tenant]);
    }

    public function store(Request $request, TenantManagementService $tenants): RedirectResponse
    {
        $tenants->create($tenants->validated($request), $request->user());

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant created and temporary password sent to owner email.');
    }

    public function edit(Tenant $tenant): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403);

        return view('admin.tenants.form', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant, TenantManagementService $tenants): RedirectResponse
    {
        $tenants->update($tenant, $tenants->validated($request, $tenant), $request->user());

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant updated.');
    }

    public function sendOwnerResetLink(Request $request, Tenant $tenant, TenantManagementService $tenants): RedirectResponse
    {
        try {
            $message = $tenants->sendOwnerResetLink($tenant, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('status', $message);
    }

    public function destroy(Request $request, Tenant $tenant, TenantManagementService $tenants): RedirectResponse
    {
        $tenants->delete($tenant, $request->user());

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant deleted.');
    }
}
