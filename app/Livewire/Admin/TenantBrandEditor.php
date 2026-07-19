<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Services\TenantBrandService;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class TenantBrandEditor extends Component
{
    use WithFileUploads;

    public Tenant $tenant;

    public string $brand_color = '#0f766e';

    public string $public_site_tagline = '';

    public string $public_site_about = '';

    public string $contact_phone = '';

    public string $contact_email = '';

    public string $contact_address = '';

    public ?TemporaryUploadedFile $logo_image = null;

    public ?TemporaryUploadedFile $hero_image = null;

    public ?TemporaryUploadedFile $flyer_image = null;

    public array $slider_images = [];

    public bool $remove_logo_image = false;

    public bool $remove_hero_image = false;

    public bool $remove_flyer_image = false;

    public bool $clear_slider_images = false;

    public ?string $savedMessage = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->fillFromTenant($tenant);
    }

    public function save(TenantBrandService $brands): void
    {
        $data = $this->validate($brands->rules());

        $this->tenant = $brands->update($this->tenant, $data);

        $this->logo_image = null;
        $this->hero_image = null;
        $this->flyer_image = null;
        $this->slider_images = [];
        $this->remove_logo_image = false;
        $this->remove_hero_image = false;
        $this->remove_flyer_image = false;
        $this->clear_slider_images = false;
        $this->savedMessage = 'Public site brand updated.';
        $this->fillFromTenant($this->tenant);
    }

    public function render()
    {
        abort_if(auth()->user()->isSuperAdmin(), 404);
        abort_unless(auth()->user()->tenant_id === $this->tenant->id, 403);

        return view('livewire.admin.tenant-brand-editor');
    }

    private function fillFromTenant(Tenant $tenant): void
    {
        $this->brand_color = (string) ($tenant->brand_color ?: '#0f766e');
        $this->public_site_tagline = (string) $tenant->public_site_tagline;
        $this->public_site_about = (string) $tenant->public_site_about;
        $this->contact_phone = (string) $tenant->contact_phone;
        $this->contact_email = (string) ($tenant->contact_email ?: $tenant->owner_email);
        $this->contact_address = (string) $tenant->contact_address;
    }
}
