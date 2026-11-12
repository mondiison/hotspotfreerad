<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 2 (2026-11-13): a Client's "landing template" customization of
 * their own shop's hotspot portal -- mirrors TenantBrandService's own
 * image-upload/remove shape, scoped one level down to Shop instead of
 * Tenant. contact_phone (the "account details" part of the original
 * spec) already exists on Shop since 2026-10-02 and needs no new field
 * here.
 */
class ShopBrandingService
{
    public function rules(): array
    {
        return [
            'portal_welcome_message' => ['nullable', 'string', 'max:1000'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'portal_banner' => ['nullable', 'image', 'max:4096'],
            'remove_portal_banner' => ['nullable', 'boolean'],
        ];
    }

    public function update(Shop $shop, array $data): Shop
    {
        $updates = [
            'portal_welcome_message' => filled($data['portal_welcome_message'] ?? null) ? $data['portal_welcome_message'] : null,
            'contact_phone' => filled($data['contact_phone'] ?? null) ? $data['contact_phone'] : null,
        ];

        if ($data['remove_portal_banner'] ?? false) {
            $this->deletePath($shop->portal_banner_path);
            $updates['portal_banner_path'] = null;
        }

        if ($data['portal_banner'] ?? null) {
            $this->deletePath($shop->portal_banner_path);
            $updates['portal_banner_path'] = $data['portal_banner']->store('shop-brand/'.$shop->id, 'public');
        }

        $shop->update($updates);

        return $shop->refresh();
    }

    private function deletePath(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
