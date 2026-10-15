<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterOsConnectionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 2026-10-15, direct report ("this page is heavy") -- mount() opens a live
 * RouterOS connection (listInterfaces()) and, once mounted, polls the API
 * every 5 seconds forever via wire:poll, regardless of which tab is actually
 * open. #[Lazy] defers both the connection attempt and the first render
 * until this component's placeholder actually scrolls into view (Livewire's
 * default x-intersect behavior) -- since the Live tab's panel is
 * display:none until selected (Flux's CSS-only tab visibility), that never
 * happens until an admin actually opens the Live tab. wire:poll's own
 * `.visible` modifier (see the view) is the other half: it stops polling
 * again the moment the tab is switched away from, since the already-mounted
 * component's underlying DOM node goes right back to display:none.
 */
#[Lazy]
class RouterLiveMonitor extends Component
{
    #[Locked]
    public int $routerId;

    public string $interface = 'wg-saas';

    public array $interfaces = [];

    public ?string $interfacesError = null;

    public array $samples = [];

    public ?string $error = null;

    public bool $polling = false;

    public bool $pollingPaused = false;

    private const MAX_SAMPLES = 30;

    public function mount(Router $router, RouterOsConnectionService $routerOs): void
    {
        $this->routerId = $router->id;
        $this->loadInterfaces($routerOs, $router);
    }

    public function refreshInterfaces(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if ($router) {
            $this->pollingPaused = false;
            $this->loadInterfaces($routerOs, $router);
        }
    }

    public function updatedInterface(): void
    {
        $this->samples = [];
        $this->error = null;
        $this->pollingPaused = false;
    }

    private function loadInterfaces(RouterOsConnectionService $routerOs, Router $router): void
    {
        $result = $routerOs->listInterfaces($router);

        if (! $result['success']) {
            $this->interfacesError = $result['error'];
            $this->pollingPaused = true;

            return;
        }

        $this->interfacesError = null;
        $this->pollingPaused = false;
        $this->interfaces = $result['interfaces'];

        if ($this->interfaces !== [] && ! in_array($this->interface, array_column($this->interfaces, 'name'), true)) {
            $this->interface = $this->interfaces[0]['name'];
        }
    }

    public function poll(RouterOsConnectionService $routerOs): void
    {
        if ($this->pollingPaused) {
            return;
        }

        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $this->polling = true;
        $result = $routerOs->liveTrafficSample($router, $this->interface);

        if (! $result['success']) {
            $this->error = $result['error'];
            $this->polling = false;
            $this->pollingPaused = true;

            return;
        }

        $this->error = null;
        $this->pollingPaused = false;
        $this->samples[] = [
            'time' => now()->format('H:i:s'),
            'download_mbps' => round($result['rx_bits_per_second'] / 1_000_000, 2),
            'upload_mbps' => round($result['tx_bits_per_second'] / 1_000_000, 2),
        ];

        if (count($this->samples) > self::MAX_SAMPLES) {
            $this->samples = array_slice($this->samples, -self::MAX_SAMPLES);
        }
    }

    public function placeholder(): View
    {
        return view('livewire.admin.placeholders.router-tab-loading');
    }

    public function render()
    {
        return view('livewire.admin.router-live-monitor');
    }
}
