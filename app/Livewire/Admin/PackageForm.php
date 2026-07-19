<?php

namespace App\Livewire\Admin;

use App\Models\Package as InternetPackage;
use App\Services\PackageManagementService;
use Illuminate\Support\Collection;
use Livewire\Component;

class PackageForm extends Component
{
    public ?int $packageId = null;

    public Collection $shops;

    public ?array $billingUsage = null;

    public string $shop_id = '';

    public string $name = '';

    public string $radius_group_name = '';

    public string $price = '';

    public string $currency = 'NGN';

    public string $limit_uptime_seconds = '86400';

    public string $data_limit_bytes = '';

    public string $speed_limit_profile = '';

    public string $fup_data_threshold_bytes = '';

    public string $fup_speed_limit_profile = '';

    public bool $is_active = true;

    public function mount(InternetPackage $package, Collection $shops, ?array $billingUsage = null): void
    {
        $this->packageId = $package->exists ? $package->id : null;
        $this->shops = $shops;
        $this->billingUsage = $billingUsage;

        if (! $package->exists) {
            return;
        }

        $this->shop_id = (string) $package->shop_id;
        $this->name = (string) $package->name;
        $this->radius_group_name = (string) $package->radius_group_name;
        $this->price = (string) $package->price;
        $this->currency = (string) $package->currency;
        $this->limit_uptime_seconds = (string) $package->limit_uptime_seconds;
        $this->data_limit_bytes = (string) $package->data_limit_bytes;
        $this->speed_limit_profile = (string) $package->speed_limit_profile;
        $this->fup_data_threshold_bytes = (string) $package->fup_data_threshold_bytes;
        $this->fup_speed_limit_profile = (string) $package->fup_speed_limit_profile;
        $this->is_active = (bool) $package->is_active;
    }

    public function setPreset(string $field, string $value): void
    {
        if (! in_array($field, ['limit_uptime_seconds', 'data_limit_bytes', 'speed_limit_profile', 'fup_data_threshold_bytes', 'fup_speed_limit_profile'], true)) {
            return;
        }

        $this->{$field} = $value;
    }

    public function save(PackageManagementService $packages)
    {
        $package = $this->package();

        $data = $this->validate($packages->rules(auth()->user(), $package));

        if ($package) {
            $packages->update($package, $data, auth()->user());

            return redirect()->route('admin.packages.index')->with('status', 'Package updated and synced to RADIUS profile.');
        }

        $packages->create($data, auth()->user());

        return redirect()->route('admin.packages.index')->with('status', 'Package created and synced to RADIUS profile.');
    }

    public function render()
    {
        return view('livewire.admin.package-form', [
            'isEditing' => filled($this->packageId),
        ]);
    }

    private function package(): ?InternetPackage
    {
        return $this->packageId ? InternetPackage::findOrFail($this->packageId) : null;
    }
}
