<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Services\SecurityActivityReportService;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityActivitiesIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action_group = '';

    public string $tenant_id = '';

    public string $date_preset = '30';

    protected $queryString = [
        'search' => ['except' => ''],
        'action_group' => ['except' => ''],
        'tenant_id' => ['except' => ''],
        'date_preset' => ['except' => '30'],
    ];

    public function updated($property): void
    {
        if (in_array($property, ['search', 'action_group', 'tenant_id', 'date_preset'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'action_group', 'tenant_id']);
        $this->date_preset = '30';
        $this->resetPage();
    }

    public function render(SecurityActivityReportService $reports)
    {
        $filters = $reports->filters([
            'search' => $this->search,
            'action_group' => $this->action_group,
            'tenant_id' => $this->tenant_id,
            'date_preset' => $this->date_preset,
        ]);

        return view('livewire.admin.security-activities-index', [
            'activities' => $reports->query(auth()->user(), $filters)->latest()->paginate(20),
            'tenants' => $this->tenants(),
            'summary' => $reports->summary(auth()->user()),
            'actionGroups' => $reports->actionGroups(),
            'datePresets' => $reports->datePresets(),
            'exportQuery' => $reports->queryParams($filters),
        ]);
    }

    private function tenants(): Collection
    {
        if (! auth()->user()->isSuperAdmin()) {
            return collect();
        }

        return Tenant::query()->orderBy('company_name')->get();
    }
}
