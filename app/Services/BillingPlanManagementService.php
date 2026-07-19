<?php

namespace App\Services;

use App\Models\BillingPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingPlanManagementService
{
    public function rules(?BillingPlan $plan = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('billing_plans', 'slug')->ignore($plan?->id),
            ],
            'monthly_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'shop_limit' => ['nullable', 'integer', 'min:1'],
            'router_limit' => ['nullable', 'integer', 'min:1'],
            'package_limit' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request, ?BillingPlan $plan = null): array
    {
        return $this->normalize($request->validate($this->rules($plan)) + [
            'is_active' => false,
        ]);
    }

    public function create(array $data, User $user): BillingPlan
    {
        $this->assertSuperAdmin($user);

        return BillingPlan::create($this->normalize($data));
    }

    public function update(BillingPlan $plan, array $data, User $user): BillingPlan
    {
        $this->assertSuperAdmin($user);

        $plan->update($this->normalize($data));

        return $plan;
    }

    public function delete(BillingPlan $plan, User $user): void
    {
        $this->assertSuperAdmin($user);

        if ($plan->tenantSubscriptions()->exists()) {
            throw ValidationException::withMessages([
                'billing_plan' => 'This billing plan is already used by tenant subscriptions. Hide it instead of deleting it.',
            ]);
        }

        $plan->delete();
    }

    public function normalize(array $data): array
    {
        $data['slug'] = Str::slug(($data['slug'] ?? null) ?: $data['name']);
        $data['currency'] = strtoupper($data['currency']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $features = is_array($data['features'] ?? null)
            ? $data['features']
            : preg_split('/\r\n|\r|\n/', (string) ($data['features'] ?? ''));

        $data['features'] = collect($features)
            ->map(fn (string $feature): string => trim($feature))
            ->filter()
            ->values()
            ->all();

        foreach (['shop_limit', 'router_limit', 'package_limit'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? (int) $data[$field] : null;
        }

        return $data;
    }

    public function assertSuperAdmin(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 403);
    }
}
