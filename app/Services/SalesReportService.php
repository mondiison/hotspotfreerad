<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class SalesReportService
{
    public function build(User $user, array $input): array
    {
        $filters = $this->filters($input);
        $from = $filters['from_date'];
        $to = $filters['to_date'];
        $group = $filters['group'];

        $payments = TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package'])->where('status', 'successful'),
            $user
        )
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($query) use ($from, $to): void {
                        $query
                            ->whereNull('paid_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->oldest('paid_at')
            ->oldest()
            ->get();

        $grossRevenue = $payments->sum(fn (Payment $payment) => $this->grossAmount($payment));
        $shopRows = $payments
            ->groupBy(fn (Payment $payment) => $payment->shop?->name ?? 'Deleted shop')
            ->map(function ($groupedPayments, string $shopName) use ($grossRevenue): array {
                $revenue = $groupedPayments->sum(fn (Payment $payment) => $this->grossAmount($payment));

                return [
                    'shop' => $shopName,
                    'sales_count' => $groupedPayments->count(),
                    'revenue' => $revenue,
                    'share' => $grossRevenue > 0 ? round(($revenue / $grossRevenue) * 100, 1) : null,
                    'platform_fee' => $groupedPayments->sum(fn (Payment $payment) => $this->platformFee($payment)),
                    'tenant_net' => $groupedPayments->sum(fn (Payment $payment) => $this->tenantNet($payment)),
                ];
            })
            ->sortByDesc('revenue')
            ->values();
        $packageRows = $payments
            ->groupBy(fn (Payment $payment) => ($payment->package_id ?: 'deleted').'-'.($payment->shop_id ?: 'deleted'))
            ->map(function ($groupedPayments) use ($grossRevenue): array {
                $payment = $groupedPayments->first();
                $revenue = $groupedPayments->sum(fn (Payment $payment) => $this->grossAmount($payment));

                return [
                    'package' => $payment->package?->name ?? 'Deleted package',
                    'shop' => $payment->shop?->name ?? 'Deleted shop',
                    'sales_count' => $groupedPayments->count(),
                    'average_sale' => $groupedPayments->avg(fn (Payment $payment) => $this->grossAmount($payment)) ?? 0,
                    'revenue' => $revenue,
                    'share' => $grossRevenue > 0 ? round(($revenue / $grossRevenue) * 100, 1) : null,
                    'platform_fee' => $groupedPayments->sum(fn (Payment $payment) => $this->platformFee($payment)),
                    'tenant_net' => $groupedPayments->sum(fn (Payment $payment) => $this->tenantNet($payment)),
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        $expenses = TenantAccess::scopeExpenses(
            Expense::query()->with('category'),
            $user
        )
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->get();
        $expensePeriods = $expenses
            ->groupBy(fn (Expense $expense) => $this->periodKey($expense->incurred_on, $group))
            ->map(fn ($groupedExpenses) => $groupedExpenses->sum(fn (Expense $expense) => (float) $expense->amount));
        $rows = $payments
            ->groupBy(fn (Payment $payment) => $this->periodKey($payment->paid_at ?? $payment->created_at, $group))
            ->map(function ($groupedPayments, string $period) use ($expensePeriods): array {
                $tenantNet = $groupedPayments->sum(fn (Payment $payment) => $this->tenantNet($payment));
                $expenses = (float) ($expensePeriods->get($period) ?? 0);
                $profit = $tenantNet - $expenses;

                return [
                    'period' => $period,
                    'sales_count' => $groupedPayments->count(),
                    'revenue' => $groupedPayments->sum(fn (Payment $payment) => $this->grossAmount($payment)),
                    'platform_fee' => $groupedPayments->sum(fn (Payment $payment) => $this->platformFee($payment)),
                    'tenant_net' => $tenantNet,
                    'expenses' => $expenses,
                    'estimated_profit' => $profit,
                    'profit_margin' => $tenantNet > 0 ? round(($profit / $tenantNet) * 100, 1) : null,
                    'average_sale' => $groupedPayments->avg(fn (Payment $payment) => $this->grossAmount($payment)) ?? 0,
                ];
            })
            ->values();
        $expenseRows = $expenses
            ->groupBy(fn (Expense $expense) => $expense->expense_category_id ? 'category-'.$expense->expense_category_id : 'uncategorized')
            ->map(function ($groupedExpenses) use ($from, $to): array {
                $expense = $groupedExpenses->first();
                $amount = $groupedExpenses->sum(fn (Expense $expense) => (float) $expense->amount);
                $budget = $expense?->category?->monthly_budget
                    ? $this->proratedBudget((float) $expense->category->monthly_budget, $from, $to)
                    : null;

                return [
                    'category' => $expense?->category?->name ?? 'Uncategorized',
                    'count' => $groupedExpenses->count(),
                    'amount' => $amount,
                    'budget' => $budget,
                    'variance' => is_null($budget) ? null : $budget - $amount,
                    'usage' => $budget && $budget > 0 ? round(($amount / $budget) * 100, 1) : null,
                ];
            })
            ->sortByDesc('amount')
            ->values();
        $expenseTotal = $expenses->sum(fn (Expense $expense) => (float) $expense->amount);
        $tenantNet = $payments->sum(fn (Payment $payment) => $this->tenantNet($payment));
        $estimatedProfit = $tenantNet - $expenseTotal;

        return [
            'filters' => [
                'preset' => $filters['preset'],
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'group' => $group,
            ],
            'presets' => $this->presets(),
            'summary' => [
                'sales_count' => $payments->count(),
                'revenue' => $grossRevenue,
                'platform_fee' => $payments->sum(fn (Payment $payment) => $this->platformFee($payment)),
                'tenant_net' => $tenantNet,
                'expenses' => $expenseTotal,
                'estimated_profit' => $estimatedProfit,
                'profit_margin' => $tenantNet > 0 ? round(($estimatedProfit / $tenantNet) * 100, 1) : null,
                'average_sale' => $payments->avg(fn (Payment $payment) => $this->grossAmount($payment)) ?? 0,
                'period_count' => $rows->count(),
            ],
            'rows' => $rows,
            'shopRows' => $shopRows,
            'packageRows' => $packageRows,
            'expenseRows' => $expenseRows,
        ];
    }

    public function filters(array $input): array
    {
        $data = Validator::make($input, [
            'preset' => ['nullable', 'in:today,last_7_days,this_month,last_month,this_year'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group' => ['nullable', 'in:day,month,year'],
        ])->validate();

        $preset = $data['preset'] ?? null;
        if ($preset) {
            [$from, $to, $defaultGroup] = $this->presetRange($preset);
            $group = $data['group'] ?? $defaultGroup;
        } else {
            $group = $data['group'] ?? 'day';
            $from = filled($data['from'] ?? null)
                ? Carbon::parse($data['from'])->startOfDay()
                : now()->startOfMonth();
            $to = filled($data['to'] ?? null)
                ? Carbon::parse($data['to'])->endOfDay()
                : now()->endOfDay();
        }

        return [
            'preset' => $preset,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_date' => $from,
            'to_date' => $to,
            'group' => $group,
        ];
    }

    public function presets(): array
    {
        return [
            'today' => ['label' => 'Today', 'group' => 'day'],
            'last_7_days' => ['label' => '7 days', 'group' => 'day'],
            'this_month' => ['label' => 'This month', 'group' => 'day'],
            'last_month' => ['label' => 'Last month', 'group' => 'day'],
            'this_year' => ['label' => 'This year', 'group' => 'month'],
        ];
    }

    public function queryParams(array $filters): array
    {
        return array_filter([
            'preset' => $filters['preset'],
            'from' => $filters['preset'] ? null : $filters['from'],
            'to' => $filters['preset'] ? null : $filters['to'],
            'group' => $filters['group'],
        ], fn ($value) => filled($value));
    }

    public function formatMargin(?float $margin): string
    {
        return is_null($margin) ? 'No sales' : $margin.'%';
    }

    public function formatBudgetUsage(?float $usage): string
    {
        return is_null($usage) ? 'No budget' : $usage.'%';
    }

    private function presetRange(string $preset): array
    {
        $today = now();

        return match ($preset) {
            'today' => [
                $today->copy()->startOfDay(),
                $today->copy()->endOfDay(),
                'day',
            ],
            'last_7_days' => [
                $today->copy()->subDays(6)->startOfDay(),
                $today->copy()->endOfDay(),
                'day',
            ],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
                'day',
            ],
            'this_year' => [
                $today->copy()->startOfYear(),
                $today->copy()->endOfDay(),
                'month',
            ],
            default => [
                $today->copy()->startOfMonth(),
                $today->copy()->endOfDay(),
                'day',
            ],
        };
    }

    private function periodKey(Carbon $date, string $group): string
    {
        return match ($group) {
            'year' => $date->format('Y'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function grossAmount(Payment $payment): float
    {
        return (float) ($payment->gross_amount ?: $payment->amount);
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

    private function platformFee(Payment $payment): float
    {
        return (float) ($payment->platform_fee_amount ?? 0);
    }

    private function tenantNet(Payment $payment): float
    {
        if ($payment->tenant_net_amount !== null && (float) $payment->tenant_net_amount > 0) {
            return (float) $payment->tenant_net_amount;
        }

        return $this->grossAmount($payment) - $this->platformFee($payment);
    }
}
