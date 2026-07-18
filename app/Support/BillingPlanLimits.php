<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BillingPlanLimits
{
    public static function assertCanCreateShop(User $user): void
    {
        self::assertWithinLimit($user, 'shop_limit', 'shops', 'shops');
    }

    public static function assertCanCreateRouter(User $user): void
    {
        self::assertWithinLimit($user, 'router_limit', 'routers', 'routers');
    }

    public static function assertCanCreatePackage(User $user): void
    {
        self::assertWithinLimit($user, 'package_limit', 'packages', 'packages');
    }

    public static function usageSummary(User $user, string $resource): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $tenant = self::tenantWithBillingCounts($user);
        $subscription = $tenant->currentBillingSubscription;
        $plan = $subscription?->billingPlan;

        $resources = [
            'shops' => ['label' => 'locations', 'count' => (int) $tenant->shops_count, 'limit' => $plan?->shop_limit],
            'routers' => ['label' => 'routers', 'count' => (int) $tenant->routers_count, 'limit' => $plan?->router_limit],
            'packages' => ['label' => 'packages', 'count' => (int) $tenant->packages_count, 'limit' => $plan?->package_limit],
        ];

        $selected = $resources[$resource] ?? null;

        if (! $selected) {
            return null;
        }

        $limit = $selected['limit'];
        $remaining = $limit === null ? null : max(0, $limit - $selected['count']);
        $canCreate = $subscription
            && $plan
            && in_array($subscription->status, ['active', 'trialing'], true)
            && ! ($subscription->current_period_ends_at && $subscription->current_period_ends_at->isPast())
            && ! ($subscription->status === 'trialing' && $subscription->trial_ends_at && $subscription->trial_ends_at->isPast())
            && ($limit === null || $selected['count'] < $limit);

        return [
            'plan_name' => $plan?->name ?? 'No platform plan',
            'status' => $subscription ? str($subscription->status)->replace('_', ' ')->title()->toString() : 'Not subscribed',
            'resource_label' => $selected['label'],
            'used' => $selected['count'],
            'limit' => $limit,
            'limit_label' => $limit === null ? 'Unlimited' : number_format($limit),
            'remaining_label' => $remaining === null ? 'Unlimited' : number_format($remaining),
            'can_create' => $canCreate,
            'message' => $canCreate
                ? 'You can add this item under the current platform plan.'
                : 'Choose or renew a platform plan before adding more '.$selected['label'].'.',
        ];
    }

    private static function assertWithinLimit(User $user, string $limitColumn, string $countRelation, string $label): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $tenant = self::tenantWithBillingCounts($user);

        $subscription = $tenant->currentBillingSubscription;
        $plan = $subscription?->billingPlan;

        if (! $subscription || ! $plan || ! in_array($subscription->status, ['active', 'trialing'], true)) {
            throw ValidationException::withMessages([
                'billing' => 'Your tenant needs an active platform billing subscription before adding '.$label.'.',
            ]);
        }

        $periodEnded = $subscription->current_period_ends_at && $subscription->current_period_ends_at->isPast();
        $trialEnded = $subscription->trial_ends_at && $subscription->trial_ends_at->isPast();

        if ($periodEnded || ($subscription->status === 'trialing' && $trialEnded)) {
            throw ValidationException::withMessages([
                'billing' => 'Your platform billing subscription has expired. Renew it before adding '.$label.'.',
            ]);
        }

        $limit = $plan->{$limitColumn};

        if ($limit === null) {
            return;
        }

        $currentCount = (int) $tenant->{$countRelation.'_count'};

        if ($currentCount >= $limit) {
            throw ValidationException::withMessages([
                'billing' => "Your {$plan->name} plan allows {$limit} {$label}. Upgrade your platform billing plan to add more.",
            ]);
        }
    }

    private static function tenantWithBillingCounts(User $user): Tenant
    {
        return Tenant::query()
            ->with('currentBillingSubscription.billingPlan')
            ->withCount([
                'shops',
                'shops as routers_count' => fn ($query) => $query->join('routers', 'routers.shop_id', '=', 'shops.id'),
                'shops as packages_count' => fn ($query) => $query->join('packages', 'packages.shop_id', '=', 'shops.id'),
            ])
            ->findOrFail($user->tenant_id);
    }
}
