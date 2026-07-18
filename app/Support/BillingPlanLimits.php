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

    private static function assertWithinLimit(User $user, string $limitColumn, string $countRelation, string $label): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $tenant = Tenant::query()
            ->with('currentBillingSubscription.billingPlan')
            ->withCount([
                'shops',
                'shops as routers_count' => fn ($query) => $query->join('routers', 'routers.shop_id', '=', 'shops.id'),
                'shops as packages_count' => fn ($query) => $query->join('packages', 'packages.shop_id', '=', 'shops.id'),
            ])
            ->findOrFail($user->tenant_id);

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
}
