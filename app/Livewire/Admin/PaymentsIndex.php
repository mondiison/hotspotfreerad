<?php

namespace App\Livewire\Admin;

use App\Services\PaymentReportService;
use Livewire\Component;
use Livewire\WithPagination;

class PaymentsIndex extends Component
{
    use WithPagination;

    public string $preset = '';

    public string $from = '';

    public string $to = '';

    public string $search = '';

    public string $status = '';

    public string $provider = '';

    protected $queryString = [
        'preset' => ['except' => ''],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'provider' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? now()->startOfMonth()->toDateString());
        $this->to = (string) ($filters['to'] ?? now()->toDateString());
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->provider = (string) ($filters['provider'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = '';
        }

        if (in_array($property, ['preset', 'from', 'to', 'search', 'status', 'provider'], true)) {
            $this->resetPage();
        }
    }

    public function setPreset(string $preset, PaymentReportService $reports): void
    {
        if (! array_key_exists($preset, $reports->presets())) {
            return;
        }

        $filters = $reports->filters([
            'preset' => $preset,
            'status' => $this->status,
            'provider' => $this->provider,
            'search' => $this->search,
        ]);

        $this->preset = (string) $filters['preset'];
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];
        $this->resetPage();
    }

    public function useCustomRange(): void
    {
        $this->preset = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->preset = '';
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->search = '';
        $this->status = '';
        $this->provider = '';
        $this->resetPage();
    }

    public function render(PaymentReportService $reports)
    {
        $filters = $reports->filters([
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'status' => $this->status,
            'provider' => $this->provider,
        ]);

        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];

        $query = $reports->query(auth()->user(), $filters);

        return view('livewire.admin.payments-index', [
            'payments' => $query->latest()->paginate(20),
            'summary' => $reports->summary(clone $query),
            'filters' => $filters,
            'presets' => $reports->presets(),
            'exportQuery' => $reports->queryParams($filters),
        ]);
    }
}
