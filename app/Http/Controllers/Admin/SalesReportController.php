<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class SalesReportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group' => ['nullable', 'in:day,month,year'],
        ]);

        $group = $data['group'] ?? 'day';
        $from = filled($data['from'] ?? null)
            ? Carbon::parse($data['from'])->startOfDay()
            : now()->startOfMonth();
        $to = filled($data['to'] ?? null)
            ? Carbon::parse($data['to'])->endOfDay()
            : now()->endOfDay();

        $payments = TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package'])->where('status', 'successful'),
            $request->user()
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

        $rows = $payments
            ->groupBy(fn (Payment $payment) => $this->periodKey($payment->paid_at ?? $payment->created_at, $group))
            ->map(fn ($groupedPayments, string $period) => [
                'period' => $period,
                'sales_count' => $groupedPayments->count(),
                'revenue' => $groupedPayments->sum(fn (Payment $payment) => $this->grossAmount($payment)),
                'platform_fee' => $groupedPayments->sum(fn (Payment $payment) => $this->platformFee($payment)),
                'tenant_net' => $groupedPayments->sum(fn (Payment $payment) => $this->tenantNet($payment)),
                'average_sale' => $groupedPayments->avg(fn (Payment $payment) => $this->grossAmount($payment)) ?? 0,
            ])
            ->values();

        $shopRows = $payments
            ->groupBy(fn (Payment $payment) => $payment->shop?->name ?? 'Deleted shop')
            ->map(fn ($groupedPayments, string $shopName) => [
                'shop' => $shopName,
                'sales_count' => $groupedPayments->count(),
                'revenue' => $groupedPayments->sum(fn (Payment $payment) => $this->grossAmount($payment)),
                'platform_fee' => $groupedPayments->sum(fn (Payment $payment) => $this->platformFee($payment)),
                'tenant_net' => $groupedPayments->sum(fn (Payment $payment) => $this->tenantNet($payment)),
            ])
            ->sortByDesc('revenue')
            ->values();

        $expenses = TenantAccess::scopeExpenses(
            Expense::query()->with('category'),
            $request->user()
        )
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->get();
        $expenseRows = $expenses
            ->groupBy(fn (Expense $expense) => $expense->category?->name ?? 'Uncategorized')
            ->map(fn ($groupedExpenses, string $category) => [
                'category' => $category,
                'count' => $groupedExpenses->count(),
                'amount' => $groupedExpenses->sum(fn (Expense $expense) => (float) $expense->amount),
            ])
            ->sortByDesc('amount')
            ->values();
        $expenseTotal = $expenses->sum(fn (Expense $expense) => (float) $expense->amount);
        $tenantNet = $payments->sum(fn (Payment $payment) => $this->tenantNet($payment));

        return view('admin.reports.sales', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'group' => $group,
            ],
            'summary' => [
                'sales_count' => $payments->count(),
                'revenue' => $payments->sum(fn (Payment $payment) => $this->grossAmount($payment)),
                'platform_fee' => $payments->sum(fn (Payment $payment) => $this->platformFee($payment)),
                'tenant_net' => $tenantNet,
                'expenses' => $expenseTotal,
                'estimated_profit' => $tenantNet - $expenseTotal,
                'average_sale' => $payments->avg(fn (Payment $payment) => $this->grossAmount($payment)) ?? 0,
                'period_count' => $rows->count(),
            ],
            'rows' => $rows,
            'shopRows' => $shopRows,
            'expenseRows' => $expenseRows,
        ]);
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
