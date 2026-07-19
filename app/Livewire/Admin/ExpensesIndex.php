<?php

namespace App\Livewire\Admin;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Services\ExpenseManagementService;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ExpensesIndex extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $tab = 'expenses';

    public string $preset = '';

    public string $from = '';

    public string $to = '';

    public string $category = '';

    public string $schedule = '';

    public string $search = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingExpenseId = null;

    public ?int $deletingExpenseId = null;

    public string $tenant_id = '';

    public string $expense_category_id = '';

    public string $title = '';

    public string $amount = '';

    public string $currency = 'NGN';

    public string $incurred_on = '';

    public string $vendor = '';

    public bool $is_recurring = false;

    public string $recurring_frequency = '';

    public string $next_due_on = '';

    public ?TemporaryUploadedFile $receipt = null;

    public bool $remove_receipt = false;

    public string $notes = '';

    public ?string $savedMessage = null;

    protected $queryString = [
        'tab' => ['except' => 'expenses'],
        'preset' => ['except' => ''],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'category' => ['except' => ''],
        'schedule' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->tab = ($filters['tab'] ?? null) === 'categories' ? 'categories' : 'expenses';
        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? now()->startOfMonth()->toDateString());
        $this->to = (string) ($filters['to'] ?? now()->toDateString());
        $this->category = (string) ($filters['category'] ?? '');
        $this->schedule = (string) ($filters['schedule'] ?? '');
        $this->search = (string) ($filters['search'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to', 'category', 'schedule', 'search'], true)) {
            $this->preset = '';
            $this->resetPage();
        }

        if ($property === 'tab') {
            $this->resetPage();
        }

        if ($property === 'tenant_id') {
            $this->expense_category_id = '';
        }
    }

    public function showExpenses(): void
    {
        $this->tab = 'expenses';
        $this->resetPage();
    }

    public function showCategories(): void
    {
        $this->tab = 'categories';
        $this->resetPage();
    }

    public function setPreset(string $preset): void
    {
        [$from, $to] = $this->presetRange($preset);

        $this->preset = $preset;
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->schedule = '';
        $this->resetPage();
    }

    public function customRange(): void
    {
        $this->preset = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['preset', 'category', 'schedule', 'search']);
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->tenant_id = (string) (auth()->user()->isSuperAdmin() ? '' : auth()->user()->tenant_id);
        $this->incurred_on = now()->toDateString();
        $this->currency = 'NGN';
        $this->showFormModal = true;
    }

    public function edit(int $expenseId): void
    {
        $expense = Expense::findOrFail($expenseId);
        TenantAccess::assertExpense($expense, auth()->user());

        $this->editingExpenseId = $expense->id;
        $this->tenant_id = (string) $expense->tenant_id;
        $this->expense_category_id = (string) $expense->expense_category_id;
        $this->title = (string) $expense->title;
        $this->amount = (string) $expense->amount;
        $this->currency = (string) $expense->currency;
        $this->incurred_on = $expense->incurred_on?->toDateString() ?? now()->toDateString();
        $this->vendor = (string) $expense->vendor;
        $this->is_recurring = (bool) $expense->is_recurring;
        $this->recurring_frequency = (string) $expense->recurring_frequency;
        $this->next_due_on = $expense->next_due_on?->toDateString() ?? '';
        $this->receipt = null;
        $this->remove_receipt = false;
        $this->notes = (string) $expense->notes;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function save(ExpenseManagementService $expenses): void
    {
        $user = auth()->user();
        $tenantId = filled($this->tenant_id) ? (int) $this->tenant_id : null;
        $expense = $this->editingExpenseId ? Expense::findOrFail($this->editingExpenseId) : null;

        if ($expense) {
            TenantAccess::assertExpense($expense, $user);
        }

        $data = Validator::make([
            'tenant_id' => $this->tenant_id,
            'expense_category_id' => $this->expense_category_id,
            'title' => $this->title,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'incurred_on' => $this->incurred_on,
            'vendor' => $this->vendor,
            'is_recurring' => $this->is_recurring,
            'recurring_frequency' => $this->recurring_frequency,
            'next_due_on' => $this->next_due_on,
            'receipt' => $this->receipt,
            'remove_receipt' => $this->remove_receipt,
            'notes' => $this->notes,
        ], $expenses->rules($user, $tenantId))->validate();

        if ($expense) {
            $expenses->update($expense, $data, $user, $this->receipt, $this->remove_receipt);
            $this->savedMessage = 'Expense updated.';
        } else {
            $expenses->create($data, $user, $this->receipt);
            $this->savedMessage = 'Expense recorded.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $expenseId): void
    {
        $expense = Expense::findOrFail($expenseId);
        TenantAccess::assertExpense($expense, auth()->user());

        $this->deletingExpenseId = $expense->id;
        $this->showDeleteModal = true;
    }

    public function delete(ExpenseManagementService $expenses): void
    {
        if (! $this->deletingExpenseId) {
            return;
        }

        $expenses->delete(Expense::findOrFail($this->deletingExpenseId), auth()->user());

        $this->showDeleteModal = false;
        $this->deletingExpenseId = null;
        $this->savedMessage = 'Expense deleted.';
        $this->resetPage();
    }

    public function recordRecurring(int $expenseId, ExpenseManagementService $expenses): void
    {
        $expenses->recordRecurring(Expense::findOrFail($expenseId), auth()->user());

        $this->savedMessage = 'Recurring expense recorded and next due date advanced.';
        $this->resetPage();
    }

    public function render()
    {
        $this->validateOnlyFilters();

        [$from, $to, $query] = $this->filteredQuery();
        $summaryQuery = clone $query;
        $expenses = $query->latest('incurred_on')->latest()->paginate(20);
        ['summary' => $summary, 'categoryRows' => $categoryRows] = $this->expenseSummary(
            (clone $summaryQuery)->get(),
            $from,
            $to
        );

        return view('livewire.admin.expenses-index', [
            'expenses' => $expenses,
            'categories' => $this->categoriesForFilters(),
            'formCategories' => $this->categoriesForForm(),
            'tenants' => $this->tenantsFor(),
            'presets' => $this->presets(),
            'summary' => $summary,
            'categoryRows' => $categoryRows,
            'deletingExpense' => $this->deletingExpenseId ? Expense::find($this->deletingExpenseId) : null,
            'editingExpense' => $this->editingExpenseId ? Expense::find($this->editingExpenseId) : null,
        ]);
    }

    public function exportUrl(): string
    {
        return route('admin.expenses.export', array_filter([
            'preset' => $this->preset ?: null,
            'from' => $this->preset ? null : $this->from,
            'to' => $this->preset ? null : $this->to,
            'category' => $this->category ?: null,
            'schedule' => $this->schedule ?: null,
            'search' => $this->search ?: null,
        ], fn ($value) => filled($value)));
    }

    private function filteredQuery(): array
    {
        if (filled($this->preset)) {
            [$from, $to] = $this->presetRange($this->preset);
        } else {
            $from = filled($this->from) ? Carbon::parse($this->from)->startOfDay() : now()->startOfMonth();
            $to = filled($this->to) ? Carbon::parse($this->to)->endOfDay() : now()->endOfDay();
        }

        $query = TenantAccess::scopeExpenses(
            Expense::query()->with(['tenant', 'category']),
            auth()->user()
        )
            ->when(
                filled($this->schedule),
                function ($query): void {
                    $query
                        ->where('is_recurring', true)
                        ->whereNotNull('next_due_on')
                        ->when(
                            $this->schedule === 'due_soon',
                            fn ($query) => $query->whereBetween('next_due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
                        )
                        ->when(
                            $this->schedule === 'overdue',
                            fn ($query) => $query->whereDate('next_due_on', '<', now()->toDateString())
                        );
                },
                fn ($query) => $query->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            )
            ->when(filled($this->category), fn ($query) => $query->where('expense_category_id', (int) $this->category))
            ->when(filled($this->search), function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('title', 'like', "%{$this->search}%")
                        ->orWhere('vendor', 'like', "%{$this->search}%")
                        ->orWhere('notes', 'like', "%{$this->search}%");
                });
            });

        return [$from, $to, $query];
    }

    private function expenseSummary(Collection $expenses, Carbon $from, Carbon $to): array
    {
        $categoryRows = $expenses
            ->groupBy(fn (Expense $expense) => $expense->expense_category_id ? 'category-'.$expense->expense_category_id : 'uncategorized')
            ->map(function ($group) use ($from, $to): array {
                $expense = $group->first();
                $amount = $group->sum(fn (Expense $expense) => (float) $expense->amount);
                $budget = $expense?->category?->monthly_budget
                    ? $this->proratedBudget((float) $expense->category->monthly_budget, $from, $to)
                    : null;

                return [
                    'category' => $expense?->category?->name ?? 'Uncategorized',
                    'count' => $group->count(),
                    'amount' => $amount,
                    'budget' => $budget,
                    'variance' => is_null($budget) ? null : $budget - $amount,
                    'usage' => $budget && $budget > 0 ? round(($amount / $budget) * 100, 1) : null,
                ];
            })
            ->sortByDesc('amount')
            ->values();
        $budgetTotal = $categoryRows->sum(fn (array $row) => (float) ($row['budget'] ?? 0));
        $expenseTotal = $expenses->sum(fn (Expense $expense) => (float) $expense->amount);
        $budgetVariance = $budgetTotal > 0 ? $budgetTotal - $expenseTotal : null;

        return [
            'summary' => [
                'count' => $expenses->count(),
                'total' => $expenseTotal,
                'recurring' => $expenses->where('is_recurring', true)->sum(fn (Expense $expense) => (float) $expense->amount),
                'budget' => $budgetTotal,
                'budget_variance' => $budgetVariance,
                'budget_usage' => $budgetTotal > 0 ? round(($expenseTotal / $budgetTotal) * 100, 1) : null,
                'overdue_count' => TenantAccess::scopeExpenses(Expense::query(), auth()->user())
                    ->where('is_recurring', true)
                    ->whereDate('next_due_on', '<', now()->toDateString())
                    ->count(),
                'category_count' => $categoryRows->count(),
            ],
            'categoryRows' => $categoryRows,
        ];
    }

    private function categoriesForFilters(): Collection
    {
        $user = auth()->user();

        return ExpenseCategory::query()
            ->where('is_active', true)
            ->where(function ($query) use ($user): void {
                $query->whereNull('tenant_id');

                if ($user->isTenantAdmin() && $user->tenant_id) {
                    $query->orWhere('tenant_id', $user->tenant_id);
                }
            })
            ->orderBy('tenant_id')
            ->orderBy('name')
            ->get();
    }

    private function categoriesForForm(): Collection
    {
        $user = auth()->user();
        $tenantId = $user->isSuperAdmin() && filled($this->tenant_id)
            ? (int) $this->tenant_id
            : $user->tenant_id;

        return ExpenseCategory::query()
            ->where('is_active', true)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id');

                if ($tenantId) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->orderBy('tenant_id')
            ->orderBy('name')
            ->get();
    }

    private function tenantsFor(): Collection
    {
        return auth()->user()->isSuperAdmin()
            ? Tenant::orderBy('company_name')->get()
            : collect();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingExpenseId',
            'tenant_id',
            'expense_category_id',
            'title',
            'amount',
            'vendor',
            'recurring_frequency',
            'next_due_on',
            'receipt',
            'remove_receipt',
            'notes',
        ]);
        $this->currency = 'NGN';
        $this->incurred_on = now()->toDateString();
        $this->is_recurring = false;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'tab' => $this->tab ?: null,
            'preset' => $this->preset ?: null,
            'from' => $this->from ?: null,
            'to' => $this->to ?: null,
            'category' => $this->category ?: null,
            'schedule' => $this->schedule ?: null,
            'search' => $this->search ?: null,
        ], [
            'tab' => ['nullable', Rule::in(['expenses', 'categories'])],
            'preset' => ['nullable', Rule::in(array_keys($this->presets()))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'schedule' => ['nullable', Rule::in(['recurring', 'due_soon', 'overdue'])],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    private function presets(): array
    {
        return [
            'today' => 'Today',
            'last_7_days' => '7 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_year' => 'This year',
        ];
    }

    private function presetRange(string $preset): array
    {
        $today = now();

        return match ($preset) {
            'today' => [
                $today->copy()->startOfDay(),
                $today->copy()->endOfDay(),
            ],
            'last_7_days' => [
                $today->copy()->subDays(6)->startOfDay(),
                $today->copy()->endOfDay(),
            ],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => [
                $today->copy()->startOfYear(),
                $today->copy()->endOfDay(),
            ],
            default => [
                $today->copy()->startOfMonth(),
                $today->copy()->endOfDay(),
            ],
        };
    }

    private function proratedBudget(float $monthlyBudget, Carbon $from, Carbon $to): float
    {
        $budget = 0.0;
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();
            $overlapStart = $from->greaterThan($monthStart) ? $from->copy() : $monthStart;
            $overlapEnd = $to->lessThan($monthEnd) ? $to->copy() : $monthEnd;
            $daysInRange = $overlapStart->copy()->startOfDay()
                ->diffInDays($overlapEnd->copy()->startOfDay()) + 1;

            $budget += $monthlyBudget * ($daysInRange / $cursor->daysInMonth);
            $cursor->addMonthNoOverflow();
        }

        return round($budget, 2);
    }
}
