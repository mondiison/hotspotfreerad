<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class TenantBrandController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.brand.edit', [
            'tenant' => $this->tenantFor($request),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->tenantFor($request);
        $data = $request->validate([
            'brand_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'public_site_tagline' => ['nullable', 'string', 'max:255'],
            'public_site_about' => ['nullable', 'string', 'max:2000'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_address' => ['nullable', 'string', 'max:1000'],
            'logo_image' => ['nullable', 'image', 'max:2048'],
            'hero_image' => ['nullable', 'image', 'max:4096'],
            'flyer_image' => ['nullable', 'image', 'max:4096'],
            'slider_images' => ['nullable', 'array', 'max:5'],
            'slider_images.*' => ['image', 'max:4096'],
            'remove_logo_image' => ['nullable', 'boolean'],
            'remove_hero_image' => ['nullable', 'boolean'],
            'remove_flyer_image' => ['nullable', 'boolean'],
            'clear_slider_images' => ['nullable', 'boolean'],
        ]);

        foreach (['logo_image', 'hero_image', 'flyer_image', 'slider_images', 'remove_logo_image', 'remove_hero_image', 'remove_flyer_image', 'clear_slider_images'] as $key) {
            unset($data[$key]);
        }

        if ($request->boolean('remove_logo_image')) {
            $this->deletePath($tenant->logo_image_path);
            $data['logo_image_path'] = null;
        }

        if ($request->boolean('remove_hero_image')) {
            $this->deletePath($tenant->hero_image_path);
            $data['hero_image_path'] = null;
        }

        if ($request->boolean('remove_flyer_image')) {
            $this->deletePath($tenant->flyer_image_path);
            $data['flyer_image_path'] = null;
        }

        if ($request->boolean('clear_slider_images')) {
            collect($tenant->public_site_slides ?? [])->each(fn ($path) => $this->deletePath($path));
            $data['public_site_slides'] = null;
        }

        if ($request->hasFile('logo_image')) {
            $this->deletePath($tenant->logo_image_path);
            $data['logo_image_path'] = $request->file('logo_image')->store('tenant-brand/'.$tenant->id, 'public');
        }

        if ($request->hasFile('hero_image')) {
            $this->deletePath($tenant->hero_image_path);
            $data['hero_image_path'] = $request->file('hero_image')->store('tenant-brand/'.$tenant->id, 'public');
        }

        if ($request->hasFile('flyer_image')) {
            $this->deletePath($tenant->flyer_image_path);
            $data['flyer_image_path'] = $request->file('flyer_image')->store('tenant-brand/'.$tenant->id, 'public');
        }

        if ($request->hasFile('slider_images')) {
            collect($tenant->public_site_slides ?? [])->each(fn ($path) => $this->deletePath($path));
            $data['public_site_slides'] = collect($request->file('slider_images'))
                ->map(fn ($file) => $file->store('tenant-brand/'.$tenant->id.'/slides', 'public'))
                ->values()
                ->all();
        }

        $tenant->update($data);

        return redirect()->route('admin.brand.edit')->with('status', 'Public site brand updated.');
    }

    private function tenantFor(Request $request): Tenant
    {
        $user = $request->user();

        abort_if($user->isSuperAdmin(), 404);

        return Tenant::findOrFail($user->tenant_id);
    }

    private function deletePath(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
