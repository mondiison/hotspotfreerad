<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterOsConnectionService;
use App\Support\RouterMetricHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 2026-10-15, direct report ("this page is heavy") -- mount() makes two live
 * RouterOS API calls (refreshLive()'s systemResource()/systemHealth()) plus
 * a RouterMetricSample query, on every router page load regardless of which
 * tab is open. #[Lazy] defers all of it until this component's placeholder
 * actually scrolls into view -- which, since the Insight tab's panel is
 * display:none until selected, only happens once an admin opens that tab.
 */
#[Lazy]
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

    public function placeholder(): View
    {
        return view('livewire.admin.placeholders.router-tab-loading');
    }

    public function render()
    {
        return view('livewire.admin.router-insight');
    }
}
