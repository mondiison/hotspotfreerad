<?php

namespace App\Support;

use App\Models\User;

/**
 * Backs the "Access" and "Transactions" primary-nav items -- each is a
 * link-tab strip across several EXISTING, unmodified routes/controllers/
 * Livewire components, not a merged route or a co-mounted flux:tab.panel
 * set. Co-mounting was considered and rejected: SubscriptionsIndex/
 * PppoeSubscribersIndex/PosDevicesIndex all bind search/status to the query
 * string, and PaymentsIndex/SalesReport both bind preset/from/to, so
 * mounting more than one of a group's Livewire components on the same page
 * at once would cross-contaminate each other's filters via the shared URL.
 * Rendering each "tab" as a real `<flux:tab href="...">` link avoids this
 * entirely (confirmed against flux-pro's own JS: an anchor-mode tab gets no
 * client-side panel-switching behavior attached, it's just a styled
 * navigation link) while keeping the visual "one tabbed page" feel.
 *
 * Every route listed here keeps its own existing StaffPermissions entry
 * unchanged -- this class only decides which of a group's links a given
 * user can currently see/reach, mirroring the per-link visibility check the
 * sidebar itself already applies.
 */
class NavGroups
{
    /**
     * @var array<string, list<array{label: string, route: string, icon: string}>>
     */
    private const GROUPS = [
        'access' => [
            ['label' => 'Hotspot', 'route' => 'admin.subscriptions.index', 'icon' => 'key'],
            ['label' => 'PPPoE', 'route' => 'admin.pppoe-subscribers.index', 'icon' => 'wifi'],
            ['label' => 'POS', 'route' => 'admin.pos-devices.index', 'icon' => 'device-phone-mobile'],
            ['label' => 'Staff & Mgmt Wi-Fi', 'route' => 'admin.trusted-wifi-devices.index', 'icon' => 'shield-check'],
        ],
        'transactions' => [
            ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'banknotes'],
            ['label' => 'Sales Report', 'route' => 'admin.reports.sales', 'icon' => 'chart-bar'],
        ],
    ];

    /**
     * @return list<array{label: string, route: string, icon: string}>
     */
    public static function visibleLinksFor(User $user, string $group): array
    {
        return array_values(array_filter(
            self::GROUPS[$group] ?? [],
            fn (array $link): bool => $user->canAccessRoute($link['route'])
        ));
    }

    /**
     * The primary-nav item's own href -- a staff member with only POS
     * permission clicking "Access" must land on the POS tab directly, not
     * 403 on a hardcoded default first tab they can't reach.
     */
    public static function firstReachableRouteFor(User $user, string $group): ?string
    {
        return self::visibleLinksFor($user, $group)[0]['route'] ?? null;
    }

    /**
     * Everything that moved out of the primary nav and into the Settings
     * area, grouped for both the sidebar's secondary nav (shown while inside
     * a settings-area route) and the Settings landing page's card grid --
     * one definition, two renderings, so they can't drift out of sync.
     *
     * @return list<array{label: string, links: list<array{label: string, route: string, icon: string, super_admin?: bool, tenant_admin?: bool}>}>
     */
    private static function settingsGroups(): array
    {
        return [
            [
                'label' => 'Shops & Network',
                'links' => [
                    ['label' => 'Shops', 'route' => 'admin.shops.index', 'icon' => 'building-storefront'],
                    ['label' => 'Routers', 'route' => 'admin.routers.index', 'icon' => 'signal'],
                    ['label' => 'Topology', 'route' => 'admin.topology.index', 'icon' => 'share'],
                    ['label' => 'Packages', 'route' => 'admin.packages.index', 'icon' => 'radio'],
                ],
            ],
            [
                'label' => 'Billing & Finance',
                'links' => [
                    ['label' => 'Billing', 'route' => 'admin.billing.index', 'icon' => 'credit-card'],
                    ['label' => 'Payment Setup', 'route' => 'admin.payment-settings.index', 'icon' => 'building-library', 'tenant_admin' => true],
                    ['label' => 'Wallet Withdrawals', 'route' => 'admin.wallet-withdrawals.index', 'icon' => 'arrow-down-tray', 'super_admin' => true],
                    ['label' => 'Expenses', 'route' => 'admin.expenses.index', 'icon' => 'receipt-percent'],
                ],
            ],
            [
                'label' => 'Team & Branding',
                'links' => [
                    ['label' => 'Users', 'route' => 'admin.users.index', 'icon' => 'users'],
                    ['label' => 'Brand', 'route' => 'admin.brand.edit', 'icon' => 'swatch', 'tenant_admin' => true],
                ],
            ],
            [
                'label' => 'Security',
                'links' => [
                    ['label' => 'Security', 'route' => 'admin.security.index', 'icon' => 'shield-check', 'super_admin' => true],
                    ['label' => 'Activity', 'route' => 'admin.security-activity.index', 'icon' => 'clock'],
                ],
            ],
            [
                'label' => 'Platform',
                'links' => [
                    ['label' => 'Tenants', 'route' => 'admin.tenants.index', 'icon' => 'building-storefront', 'super_admin' => true],
                    ['label' => 'Tools', 'route' => 'admin.tools.script-generator', 'icon' => 'wrench-screwdriver', 'super_admin' => true],
                    ['label' => 'Docs', 'route' => 'admin.docs.index', 'icon' => 'book-open', 'super_admin' => true],
                ],
            ],
            [
                'label' => 'Setup',
                'links' => [
                    ['label' => 'Setup Center', 'route' => 'admin.setup.index', 'icon' => 'rocket-launch'],
                ],
            ],
        ];
    }

    /**
     * A link is visible if it isn't super_admin-flagged for a non-super-admin,
     * isn't tenant_admin-flagged for a super_admin (super admins have no
     * personal tenant), and the user's own permission map allows the route.
     *
     * @param  array{route: string, super_admin?: bool, tenant_admin?: bool}  $link
     */
    public static function linkVisibleFor(User $user, array $link): bool
    {
        return ! (($link['super_admin'] ?? false) && ! $user->isSuperAdmin())
            && ! (($link['tenant_admin'] ?? false) && $user->isSuperAdmin())
            && $user->canAccessRoute($link['route']);
    }

    /**
     * @return list<array{label: string, links: list<array{label: string, route: string, icon: string}>}>
     */
    public static function visibleSettingsGroups(User $user): array
    {
        return array_values(array_filter(array_map(
            function (array $group) use ($user): array {
                $group['links'] = array_values(array_filter(
                    $group['links'],
                    fn (array $link): bool => self::linkVisibleFor($user, $link)
                ));

                return $group;
            },
            self::settingsGroups()
        ), fn (array $group): bool => count($group['links']) > 0));
    }
}
