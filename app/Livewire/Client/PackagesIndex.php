<?php

namespace App\Livewire\Client;

use App\Models\Package as InternetPackage;
use App\Models\Shop;
use App\Services\PackageManagementService;
use App\Support\BillingPlanLimits;
use App\Support\ClientAccess;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 2 (2026-11-12): a Client's own self-service package management --
 * deliberately a leaner sibling of Admin\PackagesIndex, not a port of it.
 * A Client typically owns a handful of shops at most, so the admin
 * component's sortable-column/filter-bar-overlay infrastructure (built for
 * a tenant admin managing dozens of packages across many shops) isn't
 * needed here; this ships a plain list + create/edit modal instead.
 *
 * Every write still funnels through the existing, proven
 * PackageManagementService -- the only new code here is the ClientAccess
 * scoping (shop_id must be one of THIS Client's own shops, not merely
 * "any shop in the same tenant," which is as far as PackageManagementService's
 * own TenantAccess-based checks go).
 */
class PackagesIndex extends Component
{
    use WithPagination;

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingPackageId = null;

    public ?int $deletingPackageId = null;

    public string $shop_id = '';

    public string $name = '';

    public string $service_type = 'hotspot';

    public string $price = '';

    public string $currency = 'NGN';

    public string $limit_uptime_seconds = '86400';

    public string $data_limit_bytes = '';

    public string $speed_limit_profile = '';

    public bool $is_active = true;

    public ?string $savedMessage = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $packageId): void
    {
        $package = InternetPackage::findOrFail($packageId);
        ClientAccess::assertPackage($package, auth()->user());

        $this->editingPackageId = $package->id;
        $this->shop_id = (string) $package->shop_id;
        $this->name = (string) $package->name;
        $this->service_type = (string) ($package->service_type ?: 'hotspot');
        $this->price = (string) $package->price;
        $this->currency = (string) $package->currency;
        $this->limit_uptime_seconds = (string) $package->limit_uptime_seconds;
        $this->data_limit_bytes = (string) $package->data_limit_bytes;
        $this->speed_limit_profile = (string) $package->speed_limit_profile;
        $this->is_active = (bool) $package->is_active;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function setPreset(string $field, string $value): void
    {
        if (! in_array($field, ['limit_uptime_seconds', 'speed_limit_profile', 'data_limit_bytes'], true)) {
            return;
        }

        $this->{$field} = $value;
    }

    public function save(PackageManagementService $packages): void
    {
        $package = $this->editingPackageId ? InternetPackage::findOrFail($this->editingPackageId) : null;

        if ($package) {
            ClientAccess::assertPackage($package, auth()->user());
        }

        // This self-service form deliberately only exposes the fields
        // below -- radius_group_name/fup_* are more advanced options not
        // essential for a Client's own package, left at their nullable
        // defaults (PackageManagementService::normalize() tolerates their
        // absence from $data entirely, and Package::$guarded = [] means
        // no mass-assignment error from simply never setting them).
        $rules = Arr::only($packages->rules(auth()->user(), $package), [
            'shop_id', 'name', 'service_type', 'price', 'currency',
            'limit_uptime_seconds', 'data_limit_bytes', 'speed_limit_profile', 'is_active',
        ]);
        $rules['shop_id'] = ['required', ClientAccess::shopExistsRule(auth()->user())];

        $data = $this->validate($rules);

        try {
            if ($package) {
                $packages->update($package, $data, auth()->user());
                $this->savedMessage = 'Package updated and synced to RADIUS profile.';
            } else {
                $packages->create($data, auth()->user());
                $this->savedMessage = 'Package created and synced to RADIUS profile.';
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0] ?? 'Unable to save package.');
            }

            return;
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $packageId): void
    {
        $package = InternetPackage::findOrFail($packageId);
        ClientAccess::assertPackage($package, auth()->user());

        $this->deletingPackageId = $package->id;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deletingPackageId) {
            return;
        }

        $package = InternetPackage::findOrFail($this->deletingPackageId);
        ClientAccess::assertPackage($package, auth()->user());
        $package->delete();

        $this->showDeleteModal = false;
        $this->deletingPackageId = null;
        $this->savedMessage = 'Package deleted.';
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();

        $packages = ClientAccess::scopePackages(InternetPackage::with('shop'), $user)
            ->where('is_system', false)
            ->latest()
            ->paginate(15);

        return view('livewire.client.packages-index', [
            'packages' => $packages,
            'shops' => $this->shops(),
            'billingUsage' => $this->editingPackageId ? null : BillingPlanLimits::usageSummary($user, 'packages'),
            'deletingPackage' => $this->deletingPackageId ? InternetPackage::find($this->deletingPackageId) : null,
        ]);
    }

    private function shops(): Collection
    {
        return ClientAccess::scopeShops(Shop::query(), auth()->user())->orderBy('name')->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingPackageId',
            'shop_id',
            'name',
            'data_limit_bytes',
            'speed_limit_profile',
        ]);
        $this->service_type = 'hotspot';
        $this->currency = 'NGN';
        $this->limit_uptime_seconds = '86400';
        $this->is_active = true;
        $this->resetValidation();

        $shops = $this->shops();
        if ($shops->count() === 1) {
            $this->shop_id = (string) $shops->first()->id;
        }
    }
}
