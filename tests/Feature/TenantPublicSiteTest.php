<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_slug_is_generated_from_company_name(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet Cafe',
            'owner_email' => 'owner@example.com',
        ]);

        $this->assertSame('mondi-internet-cafe', $tenant->slug);
    }

    public function test_public_site_displays_tenant_brand_locations_and_plans(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet Cafe',
            'owner_email' => 'owner@example.com',
            'public_site_tagline' => 'Premium Wi-Fi for daily browsing.',
            'public_site_about' => 'Reliable access for nearby customers.',
            'contact_phone' => '+234 800 000 0000',
        ]);

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Hall',
            'location_city' => 'Ibadan',
        ]);

        Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily Unlimited',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'data_limit_bytes' => 5368709120,
            'speed_limit_profile' => '5M/5M',
            'fup_data_threshold_bytes' => 2147483648,
            'fup_speed_limit_profile' => '1M/1M',
            'is_active' => true,
        ]);

        $this->get('/mondi-internet-cafe')
            ->assertOk()
            ->assertSee('Mondi Internet Cafe')
            ->assertSee('Premium Wi-Fi for daily browsing.')
            ->assertSee('Main Hall')
            ->assertSee('Ibadan')
            ->assertSee('Daily Unlimited')
            ->assertSee('NGN 1,000.00')
            ->assertSee('Admin sign in')
            ->assertSee(route('login'), false)
            ->assertSee('Featured access')
            ->assertSee('5 GB')
            ->assertSee('After 2 GB: 1M/1M')
            ->assertSee('+234 800 000 0000');
    }

    public function test_disabled_public_site_returns_not_found(): void
    {
        Tenant::create([
            'company_name' => 'Hidden Hotspot',
            'owner_email' => 'hidden@example.com',
            'public_site_enabled' => false,
        ]);

        $this->get('/hidden-hotspot')->assertNotFound();
    }
}
