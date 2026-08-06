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
            self::ROUTERS => 'Network (routers, topology, trusted Wi-Fi)',
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
        'admin.setup' => true,
        'admin.profile' => true,
        'admin.passkeys' => true,
        'admin.security-activity' => true,
        'admin.vouchers' => self::VOUCHERS,
        'admin.voucher-batches' => self::VOUCHERS,
        'admin.pos-devices' => self::POS,
        'admin.pppoe-subscribers' => self::PPPOE,
        'admin.subscriptions' => self::CUSTOMERS,
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
