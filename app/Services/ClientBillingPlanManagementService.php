<?php

namespace App\Services;

use App\Models\ClientBillingPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors BillingPlanManagementService's shape, but unlike the global,
 * super-admin-only billing_plans, each tenant defines its OWN plans for
 * its own Clients -- owned by tenant_admin, not super_admin.
 */
class ClientBillingPlanManagementService
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'shop_limit' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request): array
    {
        return $this->normalize($request->validate($this->rules()) + ['is_active' => true]);
    }

    public function create(array $data, User $user): ClientBillingPlan
    {
        abort_unless($user->isSuperAdmin() || $user->isTenantAdmin(), 403);

        return ClientBillingPlan::create($this->normalize($data) + ['tenant_id' => $user->tenant_id]);
    }

    public function update(ClientBillingPlan $plan, array $data, User $user): ClientBillingPlan
    {
        $this->assertOwner($plan, $user);

        $plan->update($this->normalize($data));

        return $plan;
    }

    public function delete(ClientBillingPlan $plan, User $user): void
    {
        $this->assertOwner($plan, $user);

        if ($plan->clientSubscriptions()->exists()) {
            throw ValidationException::withMessages([
                'client_billing_plan' => 'This plan already has Client subscriptions. Deactivate it instead of deleting it.',
            ]);
        }

        $plan->delete();
    }

    public function normalize(array $data): array
    {
        $data['currency'] = strtoupper($data['currency']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['shop_limit'] = filled($data['shop_limit'] ?? null) ? (int) $data['shop_limit'] : null;

        $features = is_array($data['features'] ?? null)
            ? $data['features']
            : preg_split('/\r\n|\r|\n/', (string) ($data['features'] ?? ''));

        $data['features'] = collect($features)
            ->map(fn (string $feature): string => trim($feature))
            ->filter()
            ->values()
            ->all();

        return $data;
    }

    public function assertOwner(ClientBillingPlan $plan, User $user): void
    {
        abort_unless($user->isSuperAdmin() || $plan->tenant_id === $user->tenant_id, 403);
    }
}
