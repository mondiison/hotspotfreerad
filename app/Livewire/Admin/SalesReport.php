<?php

namespace App\Livewire\Admin;

use App\Services\SalesReportService;
use Livewire\Component;

class SalesReport extends Component
{
    public string $preset = '';

    public string $from = '';

    public string $to = '';

    public string $group = 'day';

    protected $queryString = [
        'preset' => ['except' => ''],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'group' => ['except' => 'day'],
    ];

    public function mount(array $filters = []): void
    {
        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? now()->startOfMonth()->toDateString());
        $this->to = (string) ($filters['to'] ?? now()->toDateString());
        $this->group = (string) ($filters['group'] ?? 'day');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = '';
        }
    }

    public function setPreset(string $preset, SalesReportService $reports): void
    {
        if (! array_key_exists($preset, $reports->presets())) {
            return;
        }

        $filters = $reports->filters([
            'preset' => $preset,
        ]);

        $this->preset = (string) $filters['preset'];
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];
        $this->group = (string) $filters['group'];
    }

    public function useCustomRange(): void
    {
        $this->preset = '';
    }

    public function render(SalesReportService $reports)
    {
        $report = $reports->build(auth()->user(), [
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'group' => $this->group,
        ]);

        $this->preset = (string) ($report['filters']['preset'] ?? '');
        $this->from = (string) $report['filters']['from'];
        $this->to = (string) $report['filters']['to'];
        $this->group = (string) $report['filters']['group'];

        return view('livewire.admin.sales-report', [
            ...$report,
            'exportQuery' => $reports->queryParams($report['filters']),
        ]);
    }
}
