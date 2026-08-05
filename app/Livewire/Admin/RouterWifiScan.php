<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterOsConnectionService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RouterWifiScan extends Component
{
    #[Locked]
    public int $routerId;

    public string $interface = 'wifi1';

    public bool $legacyWireless = false;

    public array $networks = [];

    public ?string $error = null;

    public bool $hasScanned = false;

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
    }

    public function scan(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $this->hasScanned = true;
        $result = $routerOs->scanWifi($router, $this->interface, $this->legacyWireless);

        if (! $result['success']) {
            $this->error = $result['error'];
            $this->networks = [];

            return;
        }

        $this->error = null;
        $this->networks = $result['networks'];
    }

    public function render()
    {
        return view('livewire.admin.router-wifi-scan');
    }
}
