<?php

namespace App\Services;

use App\Models\Router;
use App\Models\User;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouterManagementService
{
    public function __construct(private readonly RadiusProvisioningService $radius) {}

    public function rules(User $user, ?Router $router = null): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'name' => ['required', 'string', 'max:255'],
            'nas_identifier' => ['required', 'string', 'max:255', Rule::unique('routers')->ignore($router)],
            'wireguard_internal_ip' => ['required', 'ip', Rule::unique('routers')->ignore($router)],
            'shared_secret' => [$router ? 'nullable' : 'required', 'string', 'max:255'],
            'is_online' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request, ?Router $router = null): array
    {
        return $this->normalize(
            $request->validate($this->rules($request->user(), $router)) + ['is_online' => false],
            $router
        );
    }

    public function create(array $data, User $user): Router
    {
        BillingPlanLimits::assertCanCreateRouter($user);

        $router = Router::create($this->normalize($data));
        $this->radius->syncRouter($router);

        return $router;
    }

    public function update(Router $router, array $data, User $user): Router
    {
        TenantAccess::assertRouter($router, $user);

        $router->update($this->normalize($data, $router));
        $this->radius->syncRouter($router);

        return $router;
    }

    public function delete(Router $router, User $user): void
    {
        TenantAccess::assertRouter($router, $user);

        $router->delete();
    }

    public function normalize(array $data, ?Router $router = null): array
    {
        $data['is_online'] = (bool) ($data['is_online'] ?? false);

        if ($router && blank($data['shared_secret'] ?? null)) {
            unset($data['shared_secret']);
        }

        return $data;
    }
}
