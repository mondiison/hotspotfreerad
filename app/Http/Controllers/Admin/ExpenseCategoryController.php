<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        $categories = ExpenseCategory::query()
            ->withCount('expenses')
            ->where(function ($query) use ($request, $tenantId): void {
                if ($request->user()->isSuperAdmin()) {
                    return;
                }

                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->orderByRaw('tenant_id is not null')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.expense-categories.index', compact('categories'));
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
