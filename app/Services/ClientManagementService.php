<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBillingPlan;
use App\Models\ClientBillingSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The tenant admin's own CRUD for onboarding Clients -- the reseller-tier
 * model introduced 2026-11-12. Deliberately tenant_admin-only in Phase 1
 * (super_admin can still reach it for support purposes via the same
 * TenantAccess::scopeClients()/assertClient() super-admin bypass every
 * other admin resource already uses).
 */
class ClientManagementService
{
    /**
     * client_billing_plan_id is only ever submitted (and only ever matters)
     * on create -- the edit form deliberately has no plan picker, since
     * changing a Client's plan mid-subscription is a Phase 2 concern, not
     * this phase's. $isCreate controls whether it's required at all, so an
     * update submission (which never includes it) doesn't fail validation.
     */
    public function rules(User $user, bool $isCreate = true): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'client_billing_plan_id' => [
                $isCreate ? 'required' : 'prohibited',
                Rule::exists('client_billing_plans', 'id')->where('tenant_id', $user->tenant_id),
            ],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => [TenantAccess::shopExistsRule($user)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request, bool $isCreate = true): array
    {
        return $request->validate($this->rules($request->user(), $isCreate)) + ['is_active' => true];
    }

    public function create(array $data, User $user): Client
    {
        if (! $user->isSuperAdmin() && ! $user->tenant?->client_reselling_enabled) {
            throw ValidationException::withMessages([
                'name' => 'Enable client reselling for your tenant before adding a Client.',
            ]);
        }

        return DB::transaction(function () use ($data, $user): Client {
            $client = Client::create([
                'tenant_id' => $user->tenant_id,
                'name' => $data['name'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->createTrialSubscription($client, $data['client_billing_plan_id'], $user);
            $this->syncShops($client, $data['shop_ids'] ?? [], $user);

            return $client;
        });
    }

    public function update(Client $client, array $data, User $user): Client
    {
        TenantAccess::assertClient($client, $user);

        return DB::transaction(function () use ($client, $data, $user): Client {
            $client->update([
                'name' => $data['name'],
                'is_active' => (bool) ($data['is_active'] ?? $client->is_active),
            ]);

            $this->syncShops($client, $data['shop_ids'] ?? [], $user);

            return $client;
        });
    }

    public function delete(Client $client, User $user): void
    {
        TenantAccess::assertClient($client, $user);

        // Shops revert to directly tenant-owned rather than being deleted --
        // losing a Client's billing arrangement should never take its real
        // hotspot/PPPoE/POS infrastructure (or its customers) down with it.
        Shop::where('client_id', $client->id)->update(['client_id' => null]);

        $client->delete();
    }

    /**
     * Every new Client starts on a free trial -- the tenant's own
     * default_client_trial_days (set when client reselling was enabled,
     * directly overridable by a super admin afterward).
     */
    private function createTrialSubscription(Client $client, int $planId, User $user): void
    {
        $plan = ClientBillingPlan::where('tenant_id', $user->tenant_id)->findOrFail($planId);

        ClientBillingSubscription::create([
            'client_id' => $client->id,
            'client_billing_plan_id' => $plan->id,
            'status' => 'trialing',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'trial_ends_at' => now()->addDays((int) ($user->tenant?->default_client_trial_days ?? 14)),
        ]);
    }

    /**
     * Only ever touches shops already confirmed (via shopExistsRule()) to
     * belong to this tenant -- a shop currently owned by a DIFFERENT
     * Client under the same tenant is silently reassigned to this one if
     * selected, matching how a plain "pick which shops this Client owns"
     * control is expected to behave.
     */
    private function syncShops(Client $client, array $shopIds, User $user): void
    {
        Shop::where('client_id', $client->id)->whereNotIn('id', $shopIds)->update(['client_id' => null]);

        if ($shopIds !== []) {
            TenantAccess::scopeShops(Shop::query(), $user)->whereIn('id', $shopIds)->update(['client_id' => $client->id]);
        }
    }
}
