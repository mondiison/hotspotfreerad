<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterOsConnectionService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RouterConsole extends Component
{
    #[Locked]
    public int $routerId;

    public string $command = '/system resource print';

    public array $rows = [];

    public array $columns = [];

    public ?string $path = null;

    public ?string $error = null;

    public bool $hasRun = false;

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
    }

    public function run(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $this->hasRun = true;
        $result = $routerOs->runReadOnlyCommand($router, $this->command);

        if (! $result['success']) {
            $this->error = $result['error'];
            $this->rows = [];
            $this->columns = [];
            $this->path = null;

            return;
        }

        $this->error = null;
        $this->path = $result['path'];
        $this->rows = $result['rows'];
        $this->columns = collect($this->rows)->flatMap(fn (array $row): array => array_keys($row))->unique()->values()->all();
    }

    public function render()
    {
        return view('livewire.admin.router-console');
    }
}
