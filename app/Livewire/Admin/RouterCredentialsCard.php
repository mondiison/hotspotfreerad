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

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
    }

    public function regenerateWireguardKey(RouterManagementService $routers): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $routers->regenerateWireguardKey($router, auth()->user());

        Flux::toast(
            heading: 'WireGuard key generated',
            text: 'Re-run the WireGuard section of the script on the physical router, then wait for (or trigger) the next peer sync.',
            variant: 'success',
        );
    }

    public function regenerateApiCredentials(RouterManagementService $routers): void
    {
        $router = Router::find($this->routerId);

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

        $this->testingConnection = true;
        $result = $routerOs->testConnection($router);
        $this->testingConnection = false;

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
