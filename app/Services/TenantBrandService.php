<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

class TenantBrandService
{
    public function rules(): array
    {
        return [
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
        ];
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $updates = $this->contentData($data);

        if ($data['remove_logo_image'] ?? false) {
            $this->deletePath($tenant->logo_image_path);
            $updates['logo_image_path'] = null;
        }

        if ($data['remove_hero_image'] ?? false) {
            $this->deletePath($tenant->hero_image_path);
            $updates['hero_image_path'] = null;
        }

        if ($data['remove_flyer_image'] ?? false) {
            $this->deletePath($tenant->flyer_image_path);
            $updates['flyer_image_path'] = null;
        }

        if ($data['clear_slider_images'] ?? false) {
            collect($tenant->public_site_slides ?? [])->each(fn ($path) => $this->deletePath($path));
            $updates['public_site_slides'] = null;
        }

        if ($data['logo_image'] ?? null) {
            $this->deletePath($tenant->logo_image_path);
            $updates['logo_image_path'] = $data['logo_image']->store('tenant-brand/'.$tenant->id, 'public');
        }

        if ($data['hero_image'] ?? null) {
            $this->deletePath($tenant->hero_image_path);
            $updates['hero_image_path'] = $data['hero_image']->store('tenant-brand/'.$tenant->id, 'public');
        }

        if ($data['flyer_image'] ?? null) {
            $this->deletePath($tenant->flyer_image_path);
            $updates['flyer_image_path'] = $data['flyer_image']->store('tenant-brand/'.$tenant->id, 'public');
        }

        if ($data['slider_images'] ?? null) {
            collect($tenant->public_site_slides ?? [])->each(fn ($path) => $this->deletePath($path));
            $updates['public_site_slides'] = collect($data['slider_images'])
                ->map(fn ($file) => $file->store('tenant-brand/'.$tenant->id.'/slides', 'public'))
                ->values()
                ->all();
        }

        $tenant->update($updates);

        return $tenant->refresh();
    }

    public function contentData(array $data): array
    {
        $updates = [];

        foreach (['brand_color', 'public_site_tagline', 'public_site_about', 'contact_phone', 'contact_email', 'contact_address'] as $field) {
            $updates[$field] = filled($data[$field] ?? null) ? $data[$field] : null;
        }

        $updates['brand_color'] = $updates['brand_color'] ?: '#0f766e';

        return $updates;
    }

    public function deletePath(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
