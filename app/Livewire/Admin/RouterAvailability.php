<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Support\RouterAvailabilityHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 2026-10-15, direct report ("this page is heavy") -- mount() scans up to
 * 7 days of router_metric_samples to stitch the uptime timeline, on every
 * router page load regardless of which tab is open. #[Lazy] defers that
 * query until this component's placeholder actually scrolls into view,
 * which only happens once the Availability tab is selected (Flux's tab
 * panels are display:none until then).
 */
#[Lazy]
class RouterAvailability extends Component
{
    #[Locked]
    public int $routerId;

    public string $range = '7d';

    public array $summary = [];

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
        $this->loadSummary();
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['today', '7d', '30d'], true) ? $range : '7d';
        $this->loadSummary();
    }

    public function loadSummary(): void
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

        $this->summary = app(RouterAvailabilityHistory::class)->summary($router, Carbon::instance($from), Carbon::instance($to));
    }

    public function placeholder(): View
    {
        return view('livewire.admin.placeholders.router-tab-loading');
    }

    public function render()
    {
        return view('livewire.admin.router-availability');
    }
}
