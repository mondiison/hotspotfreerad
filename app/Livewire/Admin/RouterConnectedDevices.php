<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Models\TrustedWifiDevice;
use App\Services\RouterOsConnectionService;
use App\Services\TrustedWifiDeviceManagementService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RouterConnectedDevices extends Component
{
    #[Locked]
    public int $routerId;

    public string $network = 'mgmt';

    public array $leases = [];

    public ?string $error = null;

    public bool $hasLoaded = false;

    public ?string $statusMessage = null;

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
    }

    public function updatedNetwork(): void
    {
        $this->hasLoaded = false;
        $this->leases = [];
        $this->error = null;
    }

    public function refresh(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $this->hasLoaded = true;
        $result = $routerOs->dhcpLeases($router, 'dhcp-'.$this->network);

        if (! $result['success']) {
            $this->error = $result['error'];
            $this->leases = [];

            return;
        }

        $this->error = null;
        $this->leases = $result['leases'];
    }

    public function registerAsTrusted(string $macAddress, string $hostname, TrustedWifiDeviceManagementService $devices): void
    {
        $router = Router::find($this->routerId);

        if (! $router || ! in_array($this->network, [TrustedWifiDevice::NETWORK_MGMT, TrustedWifiDevice::NETWORK_STAFF], true)) {
            return;
        }

        $devices->create([
            'shop_id' => $router->shop_id,
            'network' => $this->network,
            'device_name' => $hostname !== '' ? $hostname : 'Unnamed device',
            'mac_address' => $macAddress,
            'is_active' => true,
        ], auth()->user());

        $this->statusMessage = 'Device registered as trusted. Re-run the router script (or reapply Wi-Fi access control) to actually enforce it -- registering alone does not block or allow traffic yet.';
    }

    public function render()
    {
        return view('livewire.admin.router-connected-devices');
    }
}
