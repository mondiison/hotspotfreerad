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

    public ?string $savedDirectory = null;

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
        $this->savedDirectory = $router->hotspot_login_directory;

        if (filled($this->savedDirectory)) {
            $this->selectedDirectory = $this->savedDirectory;
        }
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

        // Confirmed live 2026-09-22: provisionHotspot() always pushed to the hardcoded
        // DEFAULT_HOTSPOT_DIRECTORY, so a router that genuinely needed a different one (a
        // real report: "flash/hotspot" vs this router's actual "hotspot") kept getting
        // silently reverted to the wrong directory on every future "Provision via API"
        // click -- saving a successful push here is what makes it stick.
        $router->forceFill(['hotspot_login_directory' => $directory])->save();
        $this->savedDirectory = $directory;

        $this->pushError = null;
        $this->statusMessage = "Login page pushed to {$directory}/login.html and saved as this router's directory -- future \"Provision via API\" pushes will use it too.";
    }

    public function resetToDefault(): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $router->forceFill(['hotspot_login_directory' => null])->save();
        $this->savedDirectory = null;
        $this->selectedDirectory = $this->defaultDirectory;
        $this->pushError = null;
        $this->statusMessage = "Reset to the default directory ({$this->defaultDirectory}). Push again to apply it live.";
    }

    public function render()
    {
        return view('livewire.admin.router-hotspot-login-page');
    }
}
