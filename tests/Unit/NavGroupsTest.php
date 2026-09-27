<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\NavGroups;
use App\Support\StaffPermissions;
use Tests\TestCase;

class NavGroupsTest extends TestCase
{
    public function test_staff_with_only_pos_permission_sees_only_the_pos_access_tab(): void
    {
        $user = User::factory()->make(['role' => 'tenant_staff', 'permissions' => [StaffPermissions::POS]]);

        $links = NavGroups::visibleLinksFor($user, 'access');

        $this->assertSame(['admin.pos-devices.index'], array_column($links, 'route'));
        $this->assertSame('admin.pos-devices.index', NavGroups::firstReachableRouteFor($user, 'access'));
    }

    public function test_staff_with_no_permissions_has_no_reachable_access_tab(): void
    {
        $user = User::factory()->make(['role' => 'tenant_staff', 'permissions' => []]);

        $this->assertSame([], NavGroups::visibleLinksFor($user, 'access'));
        $this->assertNull(NavGroups::firstReachableRouteFor($user, 'access'));
    }

    public function test_tenant_admin_sees_every_access_and_transactions_tab(): void
    {
        $user = User::factory()->make(['role' => 'tenant_admin']);

        $this->assertCount(4, NavGroups::visibleLinksFor($user, 'access'));
        $this->assertCount(2, NavGroups::visibleLinksFor($user, 'transactions'));
    }

    public function test_settings_groups_are_never_empty_for_staff_with_no_permissions(): void
    {
        $user = User::factory()->make(['role' => 'tenant_staff', 'permissions' => []]);

        $groups = NavGroups::visibleSettingsGroups($user);

        $this->assertNotEmpty($groups, 'Security Activity is unrestricted, so at least one settings group/link must remain visible.');

        $allRoutes = collect($groups)->flatMap(fn (array $group) => array_column($group['links'], 'route'));
        $this->assertTrue($allRoutes->contains('admin.security-activity.index'));
    }

    public function test_super_admin_only_links_are_hidden_from_tenant_admin(): void
    {
        $user = User::factory()->make(['role' => 'tenant_admin']);

        $groups = NavGroups::visibleSettingsGroups($user);
        $allRoutes = collect($groups)->flatMap(fn (array $group) => array_column($group['links'], 'route'));

        $this->assertFalse($allRoutes->contains('admin.tenants.index'));
        $this->assertFalse($allRoutes->contains('admin.security.index'));
        $this->assertTrue($allRoutes->contains('admin.payment-settings.index'));
    }

    public function test_tenant_admin_only_links_are_hidden_from_super_admin(): void
    {
        $user = User::factory()->make(['role' => 'super_admin', 'tenant_id' => null]);

        $groups = NavGroups::visibleSettingsGroups($user);
        $allRoutes = collect($groups)->flatMap(fn (array $group) => array_column($group['links'], 'route'));

        $this->assertFalse($allRoutes->contains('admin.payment-settings.index'));
        $this->assertFalse($allRoutes->contains('admin.brand.edit'));
        $this->assertTrue($allRoutes->contains('admin.tenants.index'));
    }
}
