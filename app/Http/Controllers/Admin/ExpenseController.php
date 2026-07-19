<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $from = filled($data['from'] ?? null) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = filled($data['to'] ?? null) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        $query = TenantAccess::scopeExpenses(
            Expense::query()->with(['tenant', 'category']),
            $request->user()
        )
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('category'), fn ($query) => $query->where('expense_category_id', $request->integer('category')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('vendor', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            });

        $summaryQuery = clone $query;
        $expenses = $query->latest('incurred_on')->latest()->paginate(20)->withQueryString();
        $categoryRows = (clone $summaryQuery)
            ->get()
            ->groupBy(fn (Expense $expense) => $expense->category?->name ?? 'Uncategorized')
            ->map(fn ($group, string $category) => [
                'category' => $category,
                'count' => $group->count(),
                'amount' => $group->sum(fn (Expense $expense) => (float) $expense->amount),
            ])
            ->sortByDesc('amount')
            ->values();

        return view('admin.expenses.index', [
            'expenses' => $expenses,
            'categories' => $this->categoriesFor($request),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'category' => $data['category'] ?? '',
                'search' => $data['search'] ?? '',
            ],
            'summary' => [
                'count' => (clone $summaryQuery)->count(),
                'total' => (clone $summaryQuery)->sum('amount'),
                'recurring' => (clone $summaryQuery)->where('is_recurring', true)->sum('amount'),
                'category_count' => $categoryRows->count(),
            ],
            'categoryRows' => $categoryRows,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.expenses.form', [
            'expense' => new Expense([
                'incurred_on' => now()->toDateString(),
                'currency' => 'NGN',
            ]),
            'categories' => $this->categoriesFor($request),
            'tenants' => $this->tenantsFor($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Expense::create($this->validated($request));

        return redirect()->route('admin.expenses.index')->with('status', 'Expense recorded.');
    }

    public function edit(Request $request, Expense $expense): View
    {
        TenantAccess::assertExpense($expense, $request->user());

        return view('admin.expenses.form', [
            'expense' => $expense,
            'categories' => $this->categoriesFor($request),
            'tenants' => $this->tenantsFor($request),
        ]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        TenantAccess::assertExpense($expense, $request->user());

        $expense->update($this->validated($request, $expense));

        return redirect()->route('admin.expenses.index')->with('status', 'Expense updated.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        TenantAccess::assertExpense($expense, $request->user());

        $expense->delete();

        return redirect()->route('admin.expenses.index')->with('status', 'Expense deleted.');
    }

    private function validated(Request $request, ?Expense $expense = null): array
    {
        $user = $request->user();
        $tenantId = $user->isSuperAdmin()
            ? $request->integer('tenant_id')
            : $user->tenant_id;

        abort_unless($tenantId, 403);

        $data = $request->validate([
            'tenant_id' => [$user->isSuperAdmin() ? 'required' : 'nullable', 'integer', Rule::exists('tenants', 'id')],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where(function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'incurred_on' => ['required', 'date'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'is_recurring' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + [
            'is_recurring' => false,
        ];

        $data['tenant_id'] = $tenantId;
        $data['currency'] = strtoupper($data['currency']);

        return $data;
    }

    private function categoriesFor(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return ExpenseCategory::query()
            ->where('is_active', true)
            ->where(function ($query) use ($tenantId, $request): void {
                $query->whereNull('tenant_id');

                if ($request->user()->isTenantAdmin() && $tenantId) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->orderBy('tenant_id')
            ->orderBy('name')
            ->get();
    }

    private function tenantsFor(Request $request)
    {
        return $request->user()->isSuperAdmin()
            ? Tenant::orderBy('company_name')->get()
            : collect();
    }
}
