<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\StaffPermissions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffPermissionsTest extends TestCase
{
    #[DataProvider('routeProvider')]
    public function test_it_maps_routes_to_the_required_permission(string $routeName, string|bool|null $expected): void
    {
        $this->assertSame($expected, StaffPermissions::forRoute($routeName));
    }

    public static function routeProvider(): array
    {
        return [
            'dashboard is always allowed' => ['admin.dashboard', true],
            'profile is always allowed' => ['admin.profile.edit', true],
            'vouchers index maps to vouchers' => ['admin.vouchers.index', StaffPermissions::VOUCHERS],
            'voucher batch export maps to vouchers' => ['admin.voucher-batches.export', StaffPermissions::VOUCHERS],
            'pos devices index maps to pos' => ['admin.pos-devices.index', StaffPermissions::POS],
            'pppoe subscribers export maps to pppoe' => ['admin.pppoe-subscribers.export', StaffPermissions::PPPOE],
            'subscriptions index maps to customers' => ['admin.subscriptions.index', StaffPermissions::CUSTOMERS],
            'routers show maps to routers' => ['admin.routers.show', StaffPermissions::ROUTERS],
            'topology maps to routers' => ['admin.topology.index', StaffPermissions::ROUTERS],
            'trusted wifi devices maps to routers' => ['admin.trusted-wifi-devices.index', StaffPermissions::ROUTERS],
            'payments index maps to payments' => ['admin.payments.index', StaffPermissions::PAYMENTS],
            'payment-settings does not collide with payments' => ['admin.payment-settings.index', null],
            'expenses maps to expenses' => ['admin.expenses.index', StaffPermissions::EXPENSES],
            'expense-categories does not collide with expenses' => ['admin.expense-categories.index', StaffPermissions::EXPENSES],
            'reports maps to reports' => ['admin.reports.sales', StaffPermissions::REPORTS],
            'users is not in the map' => ['admin.users.index', null],
            'shops is not in the map' => ['admin.shops.index', null],
            'tenants is not in the map' => ['admin.tenants.index', null],
            'billing is not in the map' => ['admin.billing.index', null],
            'packages is not in the map' => ['admin.packages.index', null],
            'brand is not in the map' => ['admin.brand.edit', null],
        ];
    }

    public function test_super_admin_can_access_every_route(): void
    {
        $user = User::factory()->make(['role' => 'super_admin']);

        $this->assertTrue($user->canAccessRoute('admin.users.index'));
        $this->assertTrue($user->canAccessRoute('admin.vouchers.index'));
        $this->assertTrue($user->canAccessRoute('anything.not.mapped'));
    }

    public function test_tenant_admin_can_access_every_route(): void
    {
        $user = User::factory()->make(['role' => 'tenant_admin']);

        $this->assertTrue($user->canAccessRoute('admin.users.index'));
        $this->assertTrue($user->canAccessRoute('admin.vouchers.index'));
    }

    public function test_tenant_staff_is_blocked_without_the_matching_permission(): void
    {
        $user = User::factory()->make(['role' => 'tenant_staff', 'permissions' => [StaffPermissions::VOUCHERS]]);

        $this->assertTrue($user->canAccessRoute('admin.vouchers.index'));
        $this->assertTrue($user->canAccessRoute('admin.dashboard'));
        $this->assertFalse($user->canAccessRoute('admin.expenses.index'));
        $this->assertFalse($user->canAccessRoute('admin.users.index'));
    }

    public function test_tenant_staff_with_no_permissions_is_blocked_from_everything_but_shared_routes(): void
    {
        $user = User::factory()->make(['role' => 'tenant_staff', 'permissions' => []]);

        $this->assertTrue($user->canAccessRoute('admin.dashboard'));
        $this->assertFalse($user->canAccessRoute('admin.vouchers.index'));
    }
}
