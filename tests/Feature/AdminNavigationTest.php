<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\StaffPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Nav Test ISP',
            'owner_email' => 'nav-owner@example.com',
        ]);
    }

    public function test_settings_landing_page_is_reachable_by_every_role(): void
    {
        $tenant = $this->makeTenant();

        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk();

        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => [],
            'is_active' => true,
        ]);
        $this->actingAs($staff)->get(route('admin.settings.index'))->assertOk();

        $superAdmin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null, 'is_active' => true]);
        $this->actingAs($superAdmin)->get(route('admin.settings.index'))->assertOk();
    }

    public function test_access_tab_strip_only_shows_the_tabs_staff_can_reach(): void
    {
        $tenant = $this->makeTenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => [StaffPermissions::POS],
            'is_active' => true,
        ]);

        $response = $this->actingAs($staff)->get(route('admin.pos-devices.index'));

        $response->assertOk();
        $response->assertSee('POS');
        $response->assertDontSee('PPPoE');
        $response->assertDontSee('Staff &amp; Mgmt Wi-Fi', false);
    }

    public function test_staff_without_pos_permission_is_forbidden_from_the_pos_tab(): void
    {
        $tenant = $this->makeTenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => [StaffPermissions::VOUCHERS],
            'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('admin.pos-devices.index'))->assertForbidden();
    }
}
