<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
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

    public function store(Request $request): RedirectResponse
    {
        ExpenseCategory::create($this->validated($request));

        return redirect()->route('admin.expense-categories.index')->with('status', 'Expense category created.');
    }

    public function edit(Request $request, ExpenseCategory $expenseCategory): View
    {
        $this->assertCanManage($request, $expenseCategory);

        return view('admin.expense-categories.form', ['category' => $expenseCategory]);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->assertCanManage($request, $expenseCategory);

        $expenseCategory->update($this->validated($request, $expenseCategory));

        return redirect()->route('admin.expense-categories.index')->with('status', 'Expense category updated.');
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->assertCanManage($request, $expenseCategory);

        if ($expenseCategory->expenses()->exists()) {
            return back()->withErrors(['category' => 'This category is already used by expenses. Deactivate it instead of deleting it.']);
        }

        $expenseCategory->delete();

        return redirect()->route('admin.expense-categories.index')->with('status', 'Expense category deleted.');
    }

    private function validated(Request $request, ?ExpenseCategory $category = null): array
    {
        $user = $request->user();
        $tenantId = $user->isSuperAdmin()
            ? null
            : $user->tenant_id;

        abort_unless($user->isSuperAdmin() || $tenantId, 403);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('expense_categories', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($category?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'is_active' => false,
        ];

        $data['tenant_id'] = $tenantId;
        $data['monthly_budget'] = filled($data['monthly_budget'] ?? null)
            ? round((float) $data['monthly_budget'], 2)
            : null;

        return $data;
    }

    private function assertCanManage(Request $request, ExpenseCategory $category): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }

        abort_unless($category->tenant_id === $request->user()->tenant_id, 403);
    }
}
