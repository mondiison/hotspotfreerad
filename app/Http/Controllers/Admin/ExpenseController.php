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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        [$data, $from, $to, $query] = $this->filteredQuery($request);

        $summaryQuery = clone $query;
        $expenses = $query->latest('incurred_on')->latest()->paginate(20)->withQueryString();
        $filteredExpenses = (clone $summaryQuery)->get();
        ['summary' => $summary, 'categoryRows' => $categoryRows] = $this->expenseSummary(
            $filteredExpenses,
            $from,
            $to,
            $request
        );

        return view('admin.expenses.index', [
            'expenses' => $expenses,
            'categories' => $this->categoriesFor($request),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'category' => $data['category'] ?? '',
                'search' => $data['search'] ?? '',
                'schedule' => $data['schedule'] ?? '',
            ],
            'summary' => [
                ...$summary,
            ],
            'categoryRows' => $categoryRows,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [, $from, $to, $query] = $this->filteredQuery($request);
        $filename = 'expenses-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';
        $exportQuery = clone $query;
        $filteredExpenses = $exportQuery
            ->oldest('incurred_on')
            ->oldest()
            ->get();
        ['summary' => $summary, 'categoryRows' => $categoryRows] = $this->expenseSummary(
            $filteredExpenses,
            $from,
            $to,
            $request
        );

        return response()->streamDownload(function () use ($filteredExpenses, $summary, $categoryRows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Tenant',
                'Title',
                'Category',
                'Vendor',
                'Amount',
                'Currency',
                'Recurring',
                'Recurring Frequency',
                'Next Due On',
                'Receipt',
                'Notes',
            ]);

            $filteredExpenses
                ->each(function (Expense $expense) use ($handle): void {
                    fputcsv($handle, [
                        $expense->incurred_on->toDateString(),
                        $expense->tenant?->company_name,
                        $expense->title,
                        $expense->category?->name ?? 'Uncategorized',
                        $expense->vendor,
                        number_format((float) $expense->amount, 2, '.', ''),
                        $expense->currency,
                        $expense->is_recurring ? 'Yes' : 'No',
                        $expense->recurring_frequency,
                        $expense->next_due_on?->toDateString(),
                        $expense->receipt_path ? 'Attached' : 'None',
                        $expense->notes,
                    ]);
                });
            fputcsv($handle, []);

            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Expenses', $summary['count']]);
            fputcsv($handle, ['Total Spent', number_format($summary['total'], 2, '.', '')]);
            fputcsv($handle, ['Budget', $summary['budget'] > 0 ? number_format($summary['budget'], 2, '.', '') : '']);
            fputcsv($handle, ['Remaining', is_null($summary['budget_variance']) ? '' : number_format($summary['budget_variance'], 2, '.', '')]);
            fputcsv($handle, ['Budget Usage', is_null($summary['budget_usage']) ? 'No budget' : $summary['budget_usage'].'%']);
            fputcsv($handle, ['Recurring', number_format($summary['recurring'], 2, '.', '')]);
            fputcsv($handle, ['Overdue', $summary['overdue_count']]);
            fputcsv($handle, ['Categories', $summary['category_count']]);
            fputcsv($handle, []);

            fputcsv($handle, ['Spend by Category']);
            fputcsv($handle, ['Category', 'Count', 'Amount', 'Budget', 'Variance', 'Usage']);
            foreach ($categoryRows as $row) {
                fputcsv($handle, [
                    $row['category'],
                    $row['count'],
                    number_format($row['amount'], 2, '.', ''),
                    is_null($row['budget']) ? '' : number_format($row['budget'], 2, '.', ''),
                    is_null($row['variance']) ? '' : number_format($row['variance'], 2, '.', ''),
                    is_null($row['usage']) ? 'No budget' : $row['usage'].'%',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
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
        $data = $this->validated($request);

        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $this->storeReceipt($request, $data['tenant_id']);
        }

        Expense::create($data);

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

        $data = $this->validated($request, $expense);

        if ($request->boolean('remove_receipt') && $expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
            $data['receipt_path'] = null;
        }

        if ($request->hasFile('receipt')) {
            if ($expense->receipt_path) {
                Storage::disk('local')->delete($expense->receipt_path);
            }

            $data['receipt_path'] = $this->storeReceipt($request, $data['tenant_id']);
        }

        $expense->update($data);

        return redirect()->route('admin.expenses.index')->with('status', 'Expense updated.');
    }

    public function receipt(Request $request, Expense $expense)
    {
        TenantAccess::assertExpense($expense, $request->user());

        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download($expense->receipt_path);
    }

    public function recordRecurring(Request $request, Expense $expense): RedirectResponse
    {
        TenantAccess::assertExpense($expense, $request->user());

        abort_unless($expense->is_recurring && $expense->recurring_frequency && $expense->next_due_on, 404);

        DB::transaction(function () use ($expense): void {
            Expense::create([
                'tenant_id' => $expense->tenant_id,
                'expense_category_id' => $expense->expense_category_id,
                'recurring_source_expense_id' => $expense->id,
                'title' => $expense->title,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'incurred_on' => $expense->next_due_on,
                'vendor' => $expense->vendor,
                'is_recurring' => false,
                'notes' => trim(($expense->notes ? $expense->notes."\n\n" : '').'Recorded from recurring schedule due '.$expense->next_due_on->toDateString().'.'),
            ]);

            $expense->update([
                'next_due_on' => $this->nextDueDate($expense->next_due_on, $expense->recurring_frequency),
            ]);
        });

        return back()->with('status', 'Recurring expense recorded and next due date advanced.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        TenantAccess::assertExpense($expense, $request->user());

        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }

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
            'recurring_frequency' => ['nullable', 'required_if:is_recurring,1', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'next_due_on' => ['nullable', 'date'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:4096'],
            'remove_receipt' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + [
            'is_recurring' => false,
        ];

        $data['tenant_id'] = $tenantId;
        $data['currency'] = strtoupper($data['currency']);

        if (! $data['is_recurring']) {
            $data['recurring_frequency'] = null;
            $data['next_due_on'] = null;
        }

        unset($data['receipt'], $data['remove_receipt']);

        return $data;
    }

    private function storeReceipt(Request $request, int $tenantId): string
    {
        return $request->file('receipt')->store("tenant-expenses/{$tenantId}", 'local');
    }

    private function expenseSummary($expenses, Carbon $from, Carbon $to, Request $request): array
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
                'overdue_count' => TenantAccess::scopeExpenses(Expense::query(), $request->user())
                    ->where('is_recurring', true)
                    ->whereDate('next_due_on', '<', now()->toDateString())
                    ->count(),
                'category_count' => $categoryRows->count(),
            ],
            'categoryRows' => $categoryRows,
        ];
    }

    private function nextDueDate(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $date->copy()->addWeek(),
            'quarterly' => $date->copy()->addQuarterNoOverflow(),
            'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(),
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

    private function filteredQuery(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'schedule' => ['nullable', Rule::in(['recurring', 'due_soon', 'overdue'])],
        ]);

        $from = filled($data['from'] ?? null) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = filled($data['to'] ?? null) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        $query = TenantAccess::scopeExpenses(
            Expense::query()->with(['tenant', 'category']),
            $request->user()
        )
            ->when(
                filled($data['schedule'] ?? null),
                function ($query) use ($data): void {
                    $query
                        ->where('is_recurring', true)
                        ->whereNotNull('next_due_on')
                        ->when(
                            $data['schedule'] === 'due_soon',
                            fn ($query) => $query->whereBetween('next_due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
                        )
                        ->when(
                            $data['schedule'] === 'overdue',
                            fn ($query) => $query->whereDate('next_due_on', '<', now()->toDateString())
                        );
                },
                fn ($query) => $query->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            )
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

        return [$data, $from, $to, $query];
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
