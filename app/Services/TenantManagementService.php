<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantAdminTemporaryPassword;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\PaystackGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantManagementService
{
    public function rules(?Tenant $tenant = null): array
    {
        $ownerUserId = $tenant
            ? $this->ownerUserFor($tenant)?->id
            : null;

        return [
            'company_name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::notIn(['admin', 'api', 'hotspot', 'login', 'logout', 'storage', 'build']),
                Rule::unique('tenants', 'slug')->ignore($tenant?->id),
            ],
            'owner_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('tenants', 'owner_email')->ignore($tenant?->id),
                Rule::unique('users', 'email')->ignore($ownerUserId),
            ],
            'subscription_plan' => ['required', 'string', 'max:50'],
            'billing_model' => ['nullable', 'string', Rule::in(['subscription', 'commission'])],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trial_ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'require_two_factor' => ['nullable', 'boolean'],
            'public_site_enabled' => ['nullable', 'boolean'],
            'brand_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'public_site_tagline' => ['nullable', 'string', 'max:255'],
            'public_site_about' => ['nullable', 'string', 'max:2000'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function validated(Request $request, ?Tenant $tenant = null): array
    {
        $this->assertSuperAdmin($request->user());

        return $this->normalize($request->validate($this->rules($tenant)) + [
            'is_active' => false,
            'require_two_factor' => false,
            'public_site_enabled' => false,
            'brand_color' => '#0f766e',
            'billing_model' => 'subscription',
            'commission_rate' => 0,
        ]);
    }

    public function create(array $data, User $actor): Tenant
    {
        $this->assertSuperAdmin($actor);
        $password = Str::random(16);

        $tenantAdmin = DB::transaction(function () use ($data, $password): User {
            $tenant = Tenant::create($this->normalize($data));

            return User::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->company_name.' Admin',
                'email' => $tenant->owner_email,
                'role' => 'tenant_admin',
                'is_active' => $tenant->is_active,
                'must_change_password' => true,
                'password' => $password,
            ]);
        });

        $tenantAdmin->notify(new TenantAdminTemporaryPassword($tenantAdmin->tenant, $password));

        return $tenantAdmin->tenant;
    }

    public function update(Tenant $tenant, array $data, User $actor): Tenant
    {
        $this->assertSuperAdmin($actor);
        $normalized = $this->normalize($data);
        $previousRequireTwoFactor = (bool) $tenant->require_two_factor;

        DB::transaction(function () use ($tenant, $normalized): void {
            $ownerUser = $this->ownerUserFor($tenant);

            $tenant->update($normalized);

            if ($ownerUser) {
                $ownerUser->update([
                    'email' => $tenant->owner_email,
                    'is_active' => $tenant->is_active,
                ]);
            }
        });

        if ($previousRequireTwoFactor !== (bool) $tenant->require_two_factor) {
            app(SecurityActivityService::class)->log(
                $actor,
                'tenant_two_factor_policy_updated',
                'Tenant two-factor policy updated.',
                [
                    'tenant_id' => $tenant->id,
                    'tenant' => $tenant->company_name,
                    'owner_email' => $tenant->owner_email,
                    'required' => (bool) $tenant->require_two_factor,
                ]
            );
        }

        return $tenant;
    }

    /**
     * 2026-10-10: separate from update()/normalize()'s general tenant-edit
     * form deliberately -- this is a super-admin-only pilot control for
     * automated subaccount settlement (see Shop::paymentGateway()), not a
     * field a tenant would ever see or set themselves, and bundling it
     * into the shared create/update data array would also need teaching
     * that array about a brand-new tenant never having one yet. Only
     * Paystack has a real integration today (GatewayCredentialResolver/
     * PaystackGateway), so that's the only accepted non-null value for
     * now -- deliberately not just "any wallet-capable gateway," since
     * picking one with no actual subaccount code path would silently do
     * nothing at charge time.
     */
    public function setSubaccountGateway(Tenant $tenant, ?string $gateway, User $actor): Tenant
    {
        $this->assertSuperAdmin($actor);

        if ($gateway !== null && $gateway !== 'paystack') {
            throw ValidationException::withMessages([
                'subaccount_settlement_gateway' => 'Only Paystack has automated subaccount settlement implemented right now.',
            ]);
        }

        $previousGateway = $tenant->subaccount_settlement_gateway;

        if ($previousGateway === $gateway) {
            return $tenant;
        }

        // A subaccount already created on the OLD gateway is meaningless
        // once the override points somewhere else (or is cleared) -- the
        // next createSubaccount() call starts fresh rather than leaving a
        // stale code lying around that no longer matches this field.
        $tenant->forceFill([
            'subaccount_settlement_gateway' => $gateway,
            'subaccount_code' => null,
            'subaccount_created_at' => null,
        ])->save();

        app(SecurityActivityService::class)->log(
            $actor,
            'tenant_subaccount_gateway_updated',
            'Tenant subaccount settlement gateway updated.',
            [
                'tenant_id' => $tenant->id,
                'tenant' => $tenant->company_name,
                'previous_gateway' => $previousGateway,
                'gateway' => $gateway,
            ]
        );

        return $tenant;
    }

    /**
     * 2026-10-10: calls Paystack's real subaccount-creation endpoint
     * using this tenant's already-verified settlement bank account
     * (WalletIndex's existing "Settlement account" flow) and the
     * tenant's own commission_rate as the platform's percentage_charge.
     * Deliberately a SEPARATE action from setSubaccountGateway() -- the
     * same "save vs. provision" split this codebase already uses
     * elsewhere (e.g. a router's saved settings vs. its own "Provision
     * via API" button) -- so a super admin can pick the gateway without
     * necessarily firing a live API call in the same step, and can retry
     * just the API call if it fails without re-picking the gateway.
     *
     * @throws RequestException
     */
    public function createSubaccount(Tenant $tenant, User $actor): Tenant
    {
        $this->assertSuperAdmin($actor);

        if ($tenant->subaccount_settlement_gateway !== 'paystack') {
            throw ValidationException::withMessages([
                'subaccount_settlement_gateway' => 'Set the subaccount settlement gateway to Paystack before creating a subaccount.',
            ]);
        }

        if (! $tenant->hasVerifiedSettlementAccount()) {
            throw ValidationException::withMessages([
                'subaccount_settlement_gateway' => 'This tenant has no verified settlement bank account yet.',
            ]);
        }

        $credentials = new GatewayCredentials(
            app(PlatformPaymentSettingsService::class)->gatewaySettings('paystack')
        );

        $result = app(PaystackGateway::class)->createSubaccount(
            $credentials,
            $tenant->company_name,
            (string) $tenant->settlement_bank_code,
            (string) $tenant->settlement_account_number,
            (float) $tenant->commission_rate
        );

        if (blank($result['subaccount_code'] ?? null)) {
            Log::warning('Paystack subaccount creation returned no subaccount_code', [
                'tenant_id' => $tenant->id,
                'response' => $result['response'] ?? null,
            ]);

            throw ValidationException::withMessages([
                'subaccount_settlement_gateway' => 'Paystack did not return a subaccount code -- check the platform Paystack credentials and try again.',
            ]);
        }

        $tenant->forceFill([
            'subaccount_code' => $result['subaccount_code'],
            'subaccount_created_at' => now(),
        ])->save();

        app(SecurityActivityService::class)->log(
            $actor,
            'tenant_subaccount_created',
            'Tenant Paystack subaccount created.',
            [
                'tenant_id' => $tenant->id,
                'tenant' => $tenant->company_name,
                'subaccount_code' => $tenant->subaccount_code,
            ]
        );

        return $tenant;
    }

    public function delete(Tenant $tenant, User $actor): void
    {
        $this->assertSuperAdmin($actor);

        $tenant->delete();
    }

    public function sendOwnerResetLink(Tenant $tenant, User $actor): string
    {
        $this->assertSuperAdmin($actor);

        $tenantAdmin = $this->ownerUserFor($tenant)
            ?? User::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->company_name.' Admin',
                'email' => $tenant->owner_email,
                'role' => 'tenant_admin',
                'is_active' => $tenant->is_active,
                'must_change_password' => true,
                'password' => Str::random(32),
            ]);

        if (! $tenantAdmin->is_active || ! $tenant->is_active) {
            throw ValidationException::withMessages([
                'owner_email' => 'The tenant owner login is inactive. Activate the tenant before sending a reset link.',
            ]);
        }

        $status = Password::sendResetLink(['email' => $tenantAdmin->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'owner_email' => __($status),
            ]);
        }

        return 'Password reset link sent to '.$tenantAdmin->email.'.';
    }

    public function ownerUserFor(Tenant $tenant): ?User
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'tenant_admin')
            ->where('email', $tenant->owner_email)
            ->first();
    }

    public function normalize(array $data): array
    {
        $data['slug'] = filled($data['slug'] ?? null) ? Str::slug($data['slug']) : null;
        $data['billing_model'] = $data['billing_model'] ?? 'subscription';
        $data['commission_rate'] = ($data['billing_model'] ?? 'subscription') === 'commission'
            ? round((float) ($data['commission_rate'] ?? 0), 2)
            : 0;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['require_two_factor'] = (bool) ($data['require_two_factor'] ?? false);
        $data['public_site_enabled'] = (bool) ($data['public_site_enabled'] ?? false);
        $data['brand_color'] = $data['brand_color'] ?? '#0f766e';

        foreach (['public_site_tagline', 'public_site_about', 'contact_phone', 'contact_email', 'contact_address', 'trial_ends_at'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? $data[$field] : null;
        }

        return $data;
    }

    public function assertSuperAdmin(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 403);
    }
}
