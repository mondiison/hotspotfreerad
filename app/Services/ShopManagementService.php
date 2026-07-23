<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\User;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Http\Request;

class ShopManagementService
{
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'location_city' => ['nullable', 'string', 'max:255'],
            'flutterwave_client_id' => ['nullable', 'string'],
            'flutterwave_client_secret' => ['nullable', 'string'],
            'flutterwave_secret_key' => ['nullable', 'string'],
            'flutterwave_webhook_secret' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request): array
    {
        return $this->normalize(
            $request->validate($this->rules()) + ['is_active' => false],
            $request->user()
        );
    }

    public function create(array $data, User $user): Shop
    {
        BillingPlanLimits::assertCanCreateShop($user);

        return Shop::create($this->normalize($data, $user));
    }

    public function update(Shop $shop, array $data, User $user): Shop
    {
        TenantAccess::assertShop($shop, $user);

        $shop->update($this->normalize($data, $user));

        return $shop;
    }

    public function delete(Shop $shop, User $user): void
    {
        TenantAccess::assertShop($shop, $user);

        $shop->delete();
    }

    public function normalize(array $data, User $user): array
    {
        if (! $user->isSuperAdmin()) {
            $data['tenant_id'] = $user->tenant_id;
        }

        $data['location_city'] = filled($data['location_city'] ?? null) ? $data['location_city'] : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        foreach (['flutterwave_client_id', 'flutterwave_client_secret', 'flutterwave_secret_key', 'flutterwave_webhook_secret'] as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }

        return $data;
    }
}
