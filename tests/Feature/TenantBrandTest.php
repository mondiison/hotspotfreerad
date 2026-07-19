<?php

namespace Tests\Feature;

use App\Livewire\Admin\TenantBrandEditor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TenantBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_view_brand_settings_with_flux_color_picker(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
            'brand_color' => '#2563eb',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.brand.edit'))
            ->assertOk()
            ->assertSee('Public Site Brand')
            ->assertSee('data-flux-color-picker', false)
            ->assertSee('#2563eb');
    }

    public function test_tenant_admin_can_update_brand_and_upload_public_media(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.brand.update'), [
                'brand_color' => '#7c3aed',
                'public_site_tagline' => 'Premium hotspot access.',
                'public_site_about' => 'Reliable access across our branches.',
                'contact_phone' => '+234 800 000 0000',
                'contact_email' => 'support@example.com',
                'contact_address' => 'Main road branch',
                'logo_image' => UploadedFile::fake()->image('logo.png', 512, 512),
                'hero_image' => UploadedFile::fake()->image('hero.jpg', 1600, 1000),
                'flyer_image' => UploadedFile::fake()->image('flyer.jpg', 800, 1000),
                'slider_images' => [
                    UploadedFile::fake()->image('slide-one.jpg', 1200, 800),
                    UploadedFile::fake()->image('slide-two.jpg', 1200, 800),
                ],
            ])
            ->assertRedirect(route('admin.brand.edit'));

        $tenant->refresh();

        $this->assertSame('#7c3aed', $tenant->brand_color);
        $this->assertSame('Premium hotspot access.', $tenant->public_site_tagline);
        $this->assertNotNull($tenant->logo_image_path);
        $this->assertNotNull($tenant->hero_image_path);
        $this->assertNotNull($tenant->flyer_image_path);
        $this->assertCount(2, $tenant->public_site_slides);

        Storage::disk('public')->assertExists($tenant->logo_image_path);
        Storage::disk('public')->assertExists($tenant->hero_image_path);
        Storage::disk('public')->assertExists($tenant->flyer_image_path);
        Storage::disk('public')->assertExists($tenant->public_site_slides[0]);

        $this->get(route('tenant.public-site', $tenant))
            ->assertOk()
            ->assertSee('Premium hotspot access.')
            ->assertSee(Storage::disk('public')->url($tenant->logo_image_path), false)
            ->assertSee(Storage::disk('public')->url($tenant->hero_image_path), false)
            ->assertSee(Storage::disk('public')->url($tenant->flyer_image_path), false)
            ->assertSee(Storage::disk('public')->url($tenant->public_site_slides[0]), false);
    }

    public function test_livewire_tenant_admin_can_update_brand_and_upload_public_media(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
            'brand_color' => '#0f766e',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(TenantBrandEditor::class, ['tenant' => $tenant])
            ->set('brand_color', '#2563eb')
            ->set('public_site_tagline', 'Fast access, simple plans.')
            ->set('public_site_about', 'Reliable hotspot access for our customers.')
            ->set('contact_phone', '+234 800 000 0000')
            ->set('contact_email', 'support@example.com')
            ->set('contact_address', 'Main road branch')
            ->set('logo_image', UploadedFile::fake()->image('logo.png', 512, 512))
            ->set('hero_image', UploadedFile::fake()->image('hero.jpg', 1600, 1000))
            ->set('flyer_image', UploadedFile::fake()->image('flyer.jpg', 800, 1000))
            ->set('slider_images', [
                UploadedFile::fake()->image('slide-one.jpg', 1200, 800),
                UploadedFile::fake()->image('slide-two.jpg', 1200, 800),
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Public site brand updated.')
            ->assertSet('brand_color', '#2563eb')
            ->assertSet('logo_image', null)
            ->assertSet('slider_images', []);

        $tenant->refresh();

        $this->assertSame('#2563eb', $tenant->brand_color);
        $this->assertSame('Fast access, simple plans.', $tenant->public_site_tagline);
        $this->assertSame('support@example.com', $tenant->contact_email);
        $this->assertNotNull($tenant->logo_image_path);
        $this->assertCount(2, $tenant->public_site_slides);

        Storage::disk('public')->assertExists($tenant->logo_image_path);
        Storage::disk('public')->assertExists($tenant->hero_image_path);
        Storage::disk('public')->assertExists($tenant->flyer_image_path);
        Storage::disk('public')->assertExists($tenant->public_site_slides[0]);
    }

    public function test_livewire_tenant_admin_can_remove_saved_brand_media(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
            'logo_image_path' => UploadedFile::fake()->image('logo.png')->store('tenant-brand/1', 'public'),
            'hero_image_path' => UploadedFile::fake()->image('hero.jpg')->store('tenant-brand/1', 'public'),
            'flyer_image_path' => UploadedFile::fake()->image('flyer.jpg')->store('tenant-brand/1', 'public'),
            'public_site_slides' => [
                UploadedFile::fake()->image('slide-one.jpg')->store('tenant-brand/1/slides', 'public'),
            ],
        ]);
        $paths = [
            $tenant->logo_image_path,
            $tenant->hero_image_path,
            $tenant->flyer_image_path,
            $tenant->public_site_slides[0],
        ];
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(TenantBrandEditor::class, ['tenant' => $tenant])
            ->set('remove_logo_image', true)
            ->set('remove_hero_image', true)
            ->set('remove_flyer_image', true)
            ->set('clear_slider_images', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Public site brand updated.');

        $tenant->refresh();

        $this->assertNull($tenant->logo_image_path);
        $this->assertNull($tenant->hero_image_path);
        $this->assertNull($tenant->flyer_image_path);
        $this->assertNull($tenant->public_site_slides);

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_super_admin_cannot_use_scoped_brand_page(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.brand.edit'))
            ->assertNotFound();
    }
}
