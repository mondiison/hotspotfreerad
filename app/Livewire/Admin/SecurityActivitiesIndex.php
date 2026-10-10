<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\SortsTable;
use App\Models\SecurityActivity;
use App\Models\Tenant;
use App\Services\SecurityActivityReportService;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityActivitiesIndex extends Component
{
    use SortsTable, WithPagination;

    public string $search = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public string $action_group = '';

    public string $action = '';

    public string $attention = '';

    public string $tenant_id = '';

    public string $date_preset = '30';

    public bool $showDetailModal = false;

    public ?int $selectedActivityId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'action_group' => ['except' => ''],
        'action' => ['except' => ''],
        'attention' => ['except' => ''],
        'tenant_id' => ['except' => ''],
        'date_preset' => ['except' => '30'],
        'sortBy' => ['except' => 'created_at'],
        'sortDirection' => ['except' => 'desc'],
    ];

    public function updated($property): void
    {
        if (in_array($property, ['search', 'action_group', 'action', 'attention', 'tenant_id', 'date_preset'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'action_group', 'action', 'attention', 'tenant_id']);
        $this->date_preset = '30';
        $this->resetPage();
    }

    /**
     * 2026-10-10, direct request to make the summary stat tiles clickable --
     * backs the "Total events" tile specifically: clears just the
     * event-type dimension (action_group/attention), leaving search/tenant/
     * date range untouched, unlike clearFilters() above which resets
     * everything including the date preset.
     */
    public function clearEventTypeFilters(): void
    {
        $this->reset(['action_group', 'attention']);
        $this->resetPage();
    }

    public function viewActivity(int $activityId): void
    {
        $activity = SecurityActivity::query()
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query->where('tenant_id', auth()->user()->tenant_id))
            ->findOrFail($activityId);

        $this->selectedActivityId = $activity->id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedActivityId = null;
    }

    protected function sortableColumns(): array
    {
        return ['created_at'];
    }

    public function render(SecurityActivityReportService $reports)
    {
        $filters = $reports->filters([
            'search' => $this->search,
            'action_group' => $this->action_group,
            'action' => $this->action,
            'attention' => $this->attention,
            'tenant_id' => $this->tenant_id,
            'date_preset' => $this->date_preset,
        ]);

        return view('livewire.admin.security-activities-index', [
            'activities' => $reports->query(auth()->user(), $filters)->orderBy($this->sortBy, $this->sortDirection)->paginate(20),
            'tenants' => $this->tenants(),
            'summary' => $reports->summary(auth()->user()),
            'actionGroups' => $reports->actionGroups(),
            'actionOptions' => $reports->actionOptions(),
            'datePresets' => $reports->datePresets(),
            'exportQuery' => $reports->queryParams($filters),
            'selectedActivity' => $this->selectedActivity(),
            'reports' => $reports,
        ]);
    }

    private function tenants(): Collection
    {
        if (! auth()->user()->isSuperAdmin()) {
            return collect();
        }

        return Tenant::query()->orderBy('company_name')->get();
    }

    private function selectedActivity(): ?SecurityActivity
    {
        if (! $this->selectedActivityId) {
            return null;
        }

        return SecurityActivity::query()
            ->with(['tenant', 'user'])
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query->where('tenant_id', auth()->user()->tenant_id))
            ->find($this->selectedActivityId);
    }
}
