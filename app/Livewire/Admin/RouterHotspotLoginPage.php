<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterOsConnectionService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RouterHotspotLoginPage extends Component
{
    public string $defaultDirectory = RouterOsConnectionService::DEFAULT_HOTSPOT_DIRECTORY;

    #[Locked]
    public int $routerId;

    public array $directories = [];

    public ?string $listError = null;

    public bool $hasListed = false;

    public string $selectedDirectory = RouterOsConnectionService::DEFAULT_HOTSPOT_DIRECTORY;

    public bool $useCustomDirectory = false;

    public string $customDirectory = '';

    public ?string $statusMessage = null;

    public ?string $pushError = null;

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
    }

    public function listDirectories(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $this->hasListed = true;
        $this->statusMessage = null;
        $this->pushError = null;
        $result = $routerOs->listHotspotDirectories($router);

        if (! $result['success']) {
            $this->listError = $result['error'];
            $this->directories = [];

            return;
        }

        $this->listError = null;
        $this->directories = $result['directories'];

        if ($this->directories !== [] && ! in_array($this->selectedDirectory, $this->directories, true)) {
            $this->selectedDirectory = $this->directories[0];
        }
    }

    public function push(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $directory = trim($this->useCustomDirectory ? $this->customDirectory : $this->selectedDirectory);

        if ($directory === '') {
            $this->pushError = 'Choose or enter a directory first.';
            $this->statusMessage = null;

            return;
        }

        $result = $routerOs->pushHotspotLoginPage($router, $directory);
        $step = $result['steps'][0] ?? null;

        if (! $result['success']) {
            $this->pushError = $step['error'] ?? 'Push failed.';
            $this->statusMessage = null;

            return;
        }

        $this->pushError = null;
        $this->statusMessage = "Login page pushed to {$directory}/login.html.";
    }

    public function render()
    {
        return view('livewire.admin.router-hotspot-login-page');
    }
}
