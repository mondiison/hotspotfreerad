<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Backs GET /api/me -- the Flutter app's one call to learn who's logged
 * in, what the tenant is branded as, and which StaffPermissions keys this
 * user can reach (so the app can hide nav items the same way the web
 * sidebar already does via User::canAccessRoute()), without a second
 * permission model to keep in sync.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'permissions' => $this->isTenantStaff() ? ($this->permissions ?? []) : null,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'brand_color' => $tenant->brand_color,
                'logo_url' => $tenant->logo_image_path
                    ? Storage::disk('public')->url($tenant->logo_image_path)
                    : null,
            ] : null,
        ];
    }
}
