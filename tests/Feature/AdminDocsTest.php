<?php

namespace Tests\Feature;

use App\Livewire\Admin\DocsIndex;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_docs_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.docs.index'))
            ->assertOk()
            ->assertSee('Docs')
            ->assertSee('HotspotFreeRAD Handover');
    }

    public function test_tenant_admin_cannot_view_the_docs_page(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant()->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.docs.index'))
            ->assertForbidden();
    }

    public function test_tenant_staff_cannot_view_the_docs_page(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant()->id,
            'role' => 'tenant_staff',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.docs.index'))
            ->assertForbidden();
    }

    public function test_it_defaults_to_the_first_priority_doc_and_renders_its_content(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(DocsIndex::class)
            ->assertSet('activeSlug', 'current-project-status')
            ->assertSeeHtml('<h1>');
    }

    public function test_search_filters_the_doc_list_by_title_and_content(): void
    {
        $component = Livewire::actingAs($this->superAdmin())
            ->test(DocsIndex::class)
            ->set('search', 'ZeroTier Fallback Tunnel');

        $this->assertSame(
            ['zerotier-fallback-setup'],
            $component->get('filteredEntries')->pluck('slug')->all()
        );

        $component
            ->set('search', 'this-string-matches-nothing-at-all')
            ->assertSee('No docs match');
    }

    public function test_opening_a_doc_updates_the_active_slug_and_hides_the_list_on_mobile(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(DocsIndex::class)
            ->call('open', 'wireguard-server-setup')
            ->assertSet('activeSlug', 'wireguard-server-setup')
            ->assertSet('showListOnMobile', false)
            ->call('back')
            ->assertSet('showListOnMobile', true);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
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
