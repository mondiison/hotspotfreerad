<?php

namespace App\Support;

class StaffPermissions
{
    public const VOUCHERS = 'vouchers';

    public const POS = 'pos';

    public const PPPOE = 'pppoe';

    public const CUSTOMERS = 'customers';

    public const ROUTERS = 'routers';

    public const PAYMENTS = 'payments';

    public const EXPENSES = 'expenses';

    public const REPORTS = 'reports';

    /**
     * @return array<string, string> permission key => human label, in the order shown on the staff form
     */
    public static function catalog(): array
    {
        return [
            self::VOUCHERS => 'Vouchers',
            self::POS => 'POS devices',
            self::PPPOE => 'PPPoE subscribers',
            self::CUSTOMERS => 'Customer access',
            // 2026-11-12: the router detail page (scripts, credentials,
            // console, live provisioning) is now super_admin-only
            // regardless of this permission -- RouterController's own
            // abort_unless() guard is the real gate on that surface now.
            // This permission's remaining reach is the router list/topology
            // view and trusted Wi-Fi -- see CLAUDE.md's dated entry.
            self::ROUTERS => 'Network (router list, topology, trusted Wi-Fi -- trusted Wi-Fi surfaces under Access, not Settings; technical router details are super-admin-only)',
            self::PAYMENTS => 'Payments',
            self::EXPENSES => 'Expenses',
            self::REPORTS => 'Reports',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Maps admin route-name prefixes to the staff permission required to reach
     * them. `true` means any authenticated tenant user may reach it regardless
     * of permissions (shared account/dashboard routes). Anything NOT listed
     * here is off-limits to tenant_staff entirely -- deny by default, so a new
     * admin route needs a deliberate decision here before staff can reach it.
     *
     * @var array<string, string|true>
     */
    private const ROUTE_PERMISSIONS = [
        'admin.dashboard' => true,
        'admin.settings' => true,
        'admin.setup' => true,
        'admin.profile' => true,
        'admin.passkeys' => true,
        'admin.security-activity' => true,
        'admin.vouchers' => self::VOUCHERS,
        'admin.voucher-batches' => self::VOUCHERS,
        // 2026-11-11: the mobile app (api.*) reuses this exact same map --
        // a staff member's access is governed by one source of truth on
        // both the web admin and the Flutter app, see CLAUDE.md's "Android
        // companion app" entry for the full reasoning. api.shops is scoped
        // to VOUCHERS for now (the only reason Phase 1 reads a shop list at
        // all is to power the voucher-creation picker) -- widen this the
        // moment a second permission area starts needing it too.
        'api.logout' => true,
        'api.me' => true,
        'api.shops' => self::VOUCHERS,
        'api.voucher-batches' => self::VOUCHERS,
        'api.vouchers' => self::VOUCHERS,
        'admin.pos-devices' => self::POS,
        'admin.pppoe-subscribers' => self::PPPOE,
        'admin.subscriptions' => self::CUSTOMERS,
        'admin.customer-contacts' => self::CUSTOMERS,
        'admin.routers' => self::ROUTERS,
        'admin.topology' => self::ROUTERS,
        'admin.trusted-wifi-devices' => self::ROUTERS,
        'admin.payments' => self::PAYMENTS,
        'admin.expenses' => self::EXPENSES,
        'admin.expense-categories' => self::EXPENSES,
        'admin.reports' => self::REPORTS,
    ];

    /**
     * @return string|true|null
     */
    public static function forRoute(?string $routeName)
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::ROUTE_PERMISSIONS as $prefix => $permission) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                return $permission;
            }
        }

        return null;
    }
}
