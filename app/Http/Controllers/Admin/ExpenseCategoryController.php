<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryManagementService;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', Rule::in(['platform', 'tenant'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'budget' => ['nullable', Rule::in(['budgeted', 'unbudgeted'])],
        ]);
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfDay()->toDateString();

        $categories = TenantAccess::scopeExpenseCategories(ExpenseCategory::query(), $user)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(($filters['scope'] ?? null) === 'platform', fn ($query) => $query->whereNull('tenant_id'))
            ->when(($filters['scope'] ?? null) === 'tenant', fn ($query) => $query->whereNotNull('tenant_id'))
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when(($filters['budget'] ?? null) === 'budgeted', fn ($query) => $query->whereNotNull('monthly_budget'))
            ->when(($filters['budget'] ?? null) === 'unbudgeted', fn ($query) => $query->whereNull('monthly_budget'))
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
            ->paginate(20)
            ->withQueryString();

        return view('admin.expense-categories.index', compact('categories', 'filters'));
    }

    public function create(): View
    {
        return view('admin.expense-categories.form', [
            'category' => new ExpenseCategory(['is_active' => true]),
        ]);
    }

    public function store(Request $request, ExpenseCategoryManagementService $categories): RedirectResponse
    {
        $categories->create($categories->validated($request), $request->user());

        return redirect()->route('admin.expense-categories.index')->with('status', 'Expense category created.');
    }

    public function edit(Request $request, ExpenseCategory $expenseCategory, ExpenseCategoryManagementService $categories): View
    {
        $categories->assertCanManage($request->user(), $expenseCategory);

        return view('admin.expense-categories.form', ['category' => $expenseCategory]);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory, ExpenseCategoryManagementService $categories): RedirectResponse
    {
        $categories->update($expenseCategory, $categories->validated($request, $expenseCategory), $request->user());

        return redirect()->route('admin.expense-categories.index')->with('status', 'Expense category updated.');
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory, ExpenseCategoryManagementService $categories): RedirectResponse
    {
        try {
            $categories->delete($expenseCategory, $request->user());
        } catch (\DomainException) {
            return back()->withErrors(['category' => 'This category is already used by expenses. Deactivate it instead of deleting it.']);
        }

        return redirect()->route('admin.expense-categories.index')->with('status', 'Expense category deleted.');
    }
}
