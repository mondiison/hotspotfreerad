<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_home_page_renders_for_guests(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(config('app.name', 'HotspotFreeRAD'));
    }

    public function test_marketing_home_page_renders_active_billing_plans(): void
    {
        \App\Models\BillingPlan::create([
            'name' => 'Growth',
            'slug' => 'growth-marketing-smoke-test',
            'monthly_price' => 25000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Growth');
        $response->assertSee('25,000');
    }

    public function test_authenticated_user_is_redirected_to_dashboard(): void
    {
        $user = \App\Models\User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('admin.dashboard'));
    }
}
