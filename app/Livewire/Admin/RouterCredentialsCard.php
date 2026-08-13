<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterManagementService;
use App\Services\RouterOsConnectionService;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RouterCredentialsCard extends Component
{
    #[Locked]
    public int $routerId;

    public bool $showRegenerateWireguardKeyModal = false;

    public bool $showRegenerateApiCredentialsModal = false;

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
    }

    public function confirmRegenerateWireguardKey(): void
    {
        $this->showRegenerateWireguardKeyModal = true;
    }

    public function regenerateWireguardKey(RouterManagementService $routers): void
    {
        $router = Router::find($this->routerId);
        $this->showRegenerateWireguardKeyModal = false;

        if (! $router) {
            return;
        }

        $routers->regenerateWireguardKey($router, auth()->user());

        Flux::toast(
            heading: 'WireGuard key generated',
            text: 'MMS Radius refreshed the Pi peer list. Re-run the WireGuard section of the script on the physical router.',
            variant: 'success',
        );
    }

    public function confirmRegenerateApiCredentials(): void
    {
        $this->showRegenerateApiCredentialsModal = true;
    }

    public function regenerateApiCredentials(RouterManagementService $routers): void
    {
        $router = Router::find($this->routerId);
        $this->showRegenerateApiCredentialsModal = false;

        if (! $router) {
            return;
        }

        $routers->regenerateApiCredentials($router, auth()->user());

        Flux::toast(
            heading: 'RouterOS API credentials generated',
            text: 'Re-run the script on this router to apply the new credentials.',
            variant: 'success',
        );
    }

    public function testConnection(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $result = $routerOs->testConnection($router);

        Flux::toast(
            heading: $result['success'] ? 'Connected' : 'Could not connect',
            text: $result['success']
                ? 'RouterOS reports identity "'.$result['identity'].'".'
                : $result['error'],
            variant: $result['success'] ? 'success' : 'danger',
        );
    }

    public function render()
    {
        return view('livewire.admin.router-credentials-card', [
            'router' => Router::find($this->routerId),
        ]);
    }
}
