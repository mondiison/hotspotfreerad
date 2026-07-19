<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantBrandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantBrandController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.brand.edit', [
            'tenant' => $this->tenantFor($request),
        ]);
    }

    public function update(Request $request, TenantBrandService $brands): RedirectResponse
    {
        $tenant = $this->tenantFor($request);
        $data = $request->validate($brands->rules());

        $brands->update($tenant, $data + [
            'remove_logo_image' => $request->boolean('remove_logo_image'),
            'remove_hero_image' => $request->boolean('remove_hero_image'),
            'remove_flyer_image' => $request->boolean('remove_flyer_image'),
            'clear_slider_images' => $request->boolean('clear_slider_images'),
        ]);

        return redirect()->route('admin.brand.edit')->with('status', 'Public site brand updated.');
    }

    private function tenantFor(Request $request): Tenant
    {
        $user = $request->user();

        abort_if($user->isSuperAdmin(), 404);

        return Tenant::findOrFail($user->tenant_id);
    }
}
