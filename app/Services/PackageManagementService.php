<?php

namespace App\Services;

use App\Models\Package as InternetPackage;
use App\Models\User;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackageManagementService
{
    public function __construct(private readonly RadiusProvisioningService $radius) {}

    public function rules(User $user, ?InternetPackage $package = null): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'name' => ['required', 'string', 'max:255'],
            'radius_group_name' => ['nullable', 'string', 'max:64', Rule::unique('packages')->ignore($package)],
            'price' => ['required', 'numeric', 'min:1', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'limit_uptime_seconds' => ['required', 'integer', 'min:60'],
            'data_limit_bytes' => ['nullable', 'integer', 'min:1'],
            'speed_limit_profile' => ['required', 'string', 'max:255'],
            'fup_data_threshold_bytes' => ['nullable', 'integer', 'min:1'],
            'fup_speed_limit_profile' => ['nullable', 'required_with:fup_data_threshold_bytes', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request, ?InternetPackage $package = null): array
    {
        return $this->normalize(
            $request->validate($this->rules($request->user(), $package)) + ['is_active' => false]
        );
    }

    public function create(array $data, User $user): InternetPackage
    {
        BillingPlanLimits::assertCanCreatePackage($user);

        $package = InternetPackage::create($this->normalize($data));
        $this->radius->syncPackageProfile($package);

        return $package;
    }

    public function update(InternetPackage $package, array $data, User $user): InternetPackage
    {
        TenantAccess::assertPackage($package, $user);

        $package->update($this->normalize($data));
        $this->radius->syncPackageProfile($package);

        return $package;
    }

    public function normalize(array $data): array
    {
        foreach (['data_limit_bytes', 'fup_data_threshold_bytes', 'fup_speed_limit_profile', 'radius_group_name'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }

        $data['currency'] = strtoupper($data['currency'] ?? 'NGN');
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}
