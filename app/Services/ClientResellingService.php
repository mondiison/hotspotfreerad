<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\BillingPlanLimits;

/**
 * Mirrors WalletService::enable() exactly, one layer deeper -- callers must
 * run BillingPlanLimits::assertCanEnableClientReselling() first. Copies the
 * tenant's own platform billing plan's defaults onto the tenant, so a
 * super admin can then override the per-tenant commission rate/trial
 * length/strictness directly (TenantsIndex), same as commission_rate is
 * already directly editable there today for commission-billing tenants.
 */
class ClientResellingService
{
    public function enable(Tenant $tenant): Tenant
    {
        $plan = $tenant->currentBillingSubscription?->billingPlan;

        $tenant->forceFill([
            'client_reselling_enabled' => true,
            'client_commission_rate' => $plan?->default_client_commission_rate ?? 0,
            'default_client_trial_days' => $plan?->default_client_trial_days ?? 14,
        ])->save();

        return $tenant;
    }

    public function disable(Tenant $tenant): Tenant
    {
        $tenant->forceFill(['client_reselling_enabled' => false])->save();

        return $tenant;
    }
}
