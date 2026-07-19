<?php

namespace App\Livewire\Admin;

use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryManagementService;
use App\Support\TenantAccess;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class ExpenseCategoriesIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $scope = '';

    public string $status = '';

    public string $budget = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingCategoryId = null;

    public ?int $deletingCategoryId = null;

    public string $name = '';

    public string $description = '';

    public string $monthly_budget = '';

    public bool $is_active = true;

    public ?string $savedMessage = null;

    public ?string $deleteError = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'scope' => ['except' => ''],
        'status' => ['except' => ''],
        'budget' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->search = (string) ($filters['search'] ?? '');
        $this->scope = (string) ($filters['scope'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->budget = (string) ($filters['budget'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'scope', 'status', 'budget'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $categoryId, ExpenseCategoryManagementService $categories): void
    {
        $category = ExpenseCategory::findOrFail($categoryId);
        $categories->assertCanManage(auth()->user(), $category);

        $this->editingCategoryId = $category->id;
        $this->name = (string) $category->name;
        $this->description = (string) $category->description;
        $this->monthly_budget = (string) $category->monthly_budget;
        $this->is_active = (bool) $category->is_active;
        $this->deleteError = null;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function save(ExpenseCategoryManagementService $categories): void
    {
        $category = $this->editingCategoryId
            ? ExpenseCategory::findOrFail($this->editingCategoryId)
            : null;

        $data = $this->validate($categories->rules(auth()->user(), $category));

        if ($category) {
            $categories->update($category, $data, auth()->user());
            $this->savedMessage = 'Expense category updated.';
        } else {
            $categories->create($data, auth()->user());
            $this->savedMessage = 'Expense category created.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $categoryId, ExpenseCategoryManagementService $categories): void
    {
        $category = ExpenseCategory::findOrFail($categoryId);
        $categories->assertCanManage(auth()->user(), $category);

        $this->deletingCategoryId = $category->id;
        $this->deleteError = null;
        $this->showDeleteModal = true;
    }

    public function delete(ExpenseCategoryManagementService $categories): void
    {
        if (! $this->deletingCategoryId) {
            return;
        }

        try {
            $categories->delete(ExpenseCategory::findOrFail($this->deletingCategoryId), auth()->user());
        } catch (\DomainException $exception) {
            $this->deleteError = $exception->getMessage();

            return;
        }

        $this->showDeleteModal = false;
        $this->deletingCategoryId = null;
        $this->deleteError = null;
        $this->savedMessage = 'Expense category deleted.';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'scope', 'status', 'budget']);
        $this->resetPage();
    }

    public function render()
    {
        $this->validateOnlyFilters();

        $user = auth()->user();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfDay()->toDateString();

        $categories = TenantAccess::scopeExpenseCategories(ExpenseCategory::query(), $user)
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%");
                });
            })
            ->when($this->scope === 'platform', fn ($query) => $query->whereNull('tenant_id'))
            ->when($this->scope === 'tenant', fn ($query) => $query->whereNotNull('tenant_id'))
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($this->budget === 'budgeted', fn ($query) => $query->whereNotNull('monthly_budget'))
            ->when($this->budget === 'unbudgeted', fn ($query) => $query->whereNull('monthly_budget'))
            ->withCount(['expenses' => function ($query) use ($user): void {
                if (! $user->isSuperAdmin()) {
                    $query->where('tenant_id', $user->tenant_id);
                }
            }])
            ->withSum(['expenses as current_month_spent' => function ($query) use ($user, $monthStart, $monthEnd): void {
                if (! $user->isSuperAdmin()) {
                    $query->where('tenant_id', $user->tenant_id);
                }

                $query
                    ->whereDate('incurred_on', '>=', $monthStart)
                    ->whereDate('incurred_on', '<=', $monthEnd);
            }], 'amount')
            ->orderByRaw('tenant_id is not null')
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.expense-categories-index', [
            'categories' => $categories,
            'deletingCategory' => $this->deletingCategoryId ? ExpenseCategory::find($this->deletingCategoryId) : null,
        ]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingCategoryId',
            'name',
            'description',
            'monthly_budget',
            'deleteError',
        ]);
        $this->is_active = true;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'scope' => $this->scope ?: null,
            'status' => $this->status ?: null,
            'budget' => $this->budget ?: null,
        ], [
            'scope' => ['nullable', Rule::in(['platform', 'tenant'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'budget' => ['nullable', Rule::in(['budgeted', 'unbudgeted'])],
        ])->validate();
    }
}
