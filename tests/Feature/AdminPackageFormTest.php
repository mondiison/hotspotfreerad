<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPackageFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_form_shows_guided_plan_controls(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.packages.create'))
            ->assertOk()
            ->assertSee($shop->name)
            ->assertSee('Plan Shape')
            ->assertSee('Unlimited')
            ->assertSee('30 days')
            ->assertSee('20GB')
            ->assertSee('512K/512K');
    }

    public function test_fup_threshold_requires_fup_speed(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.packages.store'), [
                'shop_id' => $shop->id,
                'name' => 'Fair Use Plan',
                'price' => 1000,
                'currency' => 'ngn',
                'limit_uptime_seconds' => 86400,
                'speed_limit_profile' => '5M/5M',
                'fup_data_threshold_bytes' => 5368709120,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('fup_speed_limit_profile');
    }

    private function shop(): Shop
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo Tenant',
            'owner_email' => fake()->unique()->safeEmail(),
        ]);

        return Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
