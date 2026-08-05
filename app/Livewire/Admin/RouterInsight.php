<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterOsConnectionService;
use App\Support\RouterMetricHistory;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RouterInsight extends Component
{
    #[Locked]
    public int $routerId;

    public string $range = '7d';

    public ?array $liveResource = null;

    public ?string $liveError = null;

    public array $health = [];

    public array $series = [];

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
        $this->refreshLive();
        $this->loadSeries();
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['today', '7d', '30d'], true) ? $range : '7d';
        $this->loadSeries();
    }

    public function refreshLive(): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $routerOs = app(RouterOsConnectionService::class);
        $resource = $routerOs->systemResource($router);

        if ($resource['success']) {
            $this->liveResource = $resource;
            $this->liveError = null;
        } else {
            $this->liveResource = null;
            $this->liveError = $resource['error'];
        }

        $health = $routerOs->systemHealth($router);
        $this->health = $health['success'] ? $health['fields'] : [];
    }

    public function loadSeries(): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $to = now();
        $from = match ($this->range) {
            'today' => now()->startOfDay(),
            '30d' => now()->subDays(29)->startOfDay(),
            default => now()->subDays(6)->startOfDay(),
        };

        $this->series = app(RouterMetricHistory::class)->series($router, Carbon::instance($from), Carbon::instance($to));
    }

    public function render()
    {
        return view('livewire.admin.router-insight');
    }
}
