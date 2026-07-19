<?php

namespace App\Livewire\Admin;

use App\Services\SubscriptionReportService;
use Livewire\Component;
use Livewire\WithPagination;

class SubscriptionsIndex extends Component
{
    use WithPagination;

    public string $preset = '';

    public string $from = '';

    public string $to = '';

    public string $search = '';

    public string $status = '';

    public string $source = '';

    public string $throttled = '';

    protected $queryString = [
        'preset' => ['except' => ''],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'source' => ['except' => ''],
        'throttled' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? '');
        $this->to = (string) ($filters['to'] ?? '');
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->source = (string) ($filters['source'] ?? '');
        $this->throttled = (string) ($filters['throttled'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = '';
        }

        if (in_array($property, ['preset', 'from', 'to', 'search', 'status', 'source', 'throttled'], true)) {
            $this->resetPage();
        }
    }

    public function setPreset(string $preset, SubscriptionReportService $reports): void
    {
        if (! array_key_exists($preset, $reports->presets())) {
            return;
        }

        $filters = $reports->filters([
            'preset' => $preset,
            'status' => $this->status,
            'source' => $this->source,
            'throttled' => $this->throttled,
            'search' => $this->search,
        ]);

        $this->preset = (string) $filters['preset'];
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];
        $this->resetPage();
    }

    public function showAllDates(): void
    {
        $this->preset = '';
        $this->from = '';
        $this->to = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['preset', 'from', 'to', 'search', 'status', 'source', 'throttled']);
        $this->resetPage();
    }

    public function render(SubscriptionReportService $reports)
    {
        $filters = $reports->filters([
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'status' => $this->status,
            'source' => $this->source,
            'throttled' => $this->throttled,
        ]);

        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? '');
        $this->to = (string) ($filters['to'] ?? '');

        $query = $reports->query(auth()->user(), $filters);

        return view('livewire.admin.subscriptions-index', [
            'subscriptions' => $query->latest('expires_at')->paginate(20),
            'summary' => $reports->summary(clone $query),
            'filters' => $filters,
            'presets' => $reports->presets(),
            'exportQuery' => $reports->queryParams($filters),
        ]);
    }
}
