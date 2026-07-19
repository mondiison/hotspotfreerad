<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class SalesReportController extends Controller
{
    public function index(Request $request): View
    {
        $report = $this->buildReport($request);

        return view('admin.reports.sales', $report);
    }

    public function export(Request $request): StreamedResponse
    {
        $report = $this->buildReport($request);
        $filename = 'sales-report-'.$report['filters']['from'].'-to-'.$report['filters']['to'].'.csv';

        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Sales Report']);
            fputcsv($handle, ['From', $report['filters']['from']]);
            fputcsv($handle, ['To', $report['filters']['to']]);
            fputcsv($handle, ['Group', $report['filters']['group']]);
            fputcsv($handle, []);
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Sales Count', $report['summary']['sales_count']]);
            fputcsv($handle, ['Gross Sales', number_format($report['summary']['revenue'], 2, '.', '')]);
            fputcsv($handle, ['Platform Commission', number_format($report['summary']['platform_fee'], 2, '.', '')]);
            fputcsv($handle, ['Tenant Net', number_format($report['summary']['tenant_net'], 2, '.', '')]);
            fputcsv($handle, ['Expenses', number_format($report['summary']['expenses'], 2, '.', '')]);
            fputcsv($handle, ['Estimated Profit', number_format($report['summary']['estimated_profit'], 2, '.', '')]);
            fputcsv($handle, ['Profit Margin', $this->formatMargin($report['summary']['profit_margin'])]);
            fputcsv($handle, []);

            fputcsv($handle, ['Sales by Period']);
            fputcsv($handle, ['Period', 'Sales', 'Average Sale', 'Gross Sales', 'Platform Commission', 'Tenant Net', 'Expenses', 'Estimated Profit', 'Profit Margin']);
            foreach ($report['rows'] as $row) {
                fputcsv($handle, [
                    $row['period'],
                    $row['sales_count'],
                    number_format($row['average_sale'], 2, '.', ''),
                    number_format($row['revenue'], 2, '.', ''),
                    number_format($row['platform_fee'], 2, '.', ''),
                    number_format($row['tenant_net'], 2, '.', ''),
                    number_format($row['expenses'], 2, '.', ''),
                    number_format($row['estimated_profit'], 2, '.', ''),
                    $this->formatMargin($row['profit_margin']),
                ]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Sales by Shop']);
            fputcsv($handle, ['Shop', 'Sales', 'Gross Sales', 'Share', 'Platform Commission', 'Tenant Net']);
            foreach ($report['shopRows'] as $row) {
                fputcsv($handle, [
                    $row['shop'],
                    $row['sales_count'],
                    number_format($row['revenue'], 2, '.', ''),
                    $this->formatMargin($row['share']),
                    number_format($row['platform_fee'], 2, '.', ''),
                    number_format($row['tenant_net'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Expenses by Category']);
            fputcsv($handle, ['Category', 'Count', 'Amount']);
            foreach ($report['expenseRows'] as $row) {
                fputcsv($handle, [
                    $row['category'],
                    $row['count'],
                    number_format($row['amount'], 2, '.', ''),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function buildReport(Request $request): array
    {
        $data = $request->validate([
            'preset' => ['nullable', 'in:today,last_7_days,this_month,last_month,this_year'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group' => ['nullable', 'in:day,month,year'],
        ]);

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

        $expenses = TenantAccess::scopeExpenses(
            Expense::query()->with('category'),
            $request->user()
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
        $estimatedProfit = $tenantNet - $expenseTotal;

        return [
            'filters' => [
                'preset' => $preset,
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
            'expenseRows' => $expenseRows,
        ];
    }

    private function presets(): array
    {
        return [
            'today' => ['label' => 'Today', 'group' => 'day'],
            'last_7_days' => ['label' => '7 days', 'group' => 'day'],
            'this_month' => ['label' => 'This month', 'group' => 'day'],
            'last_month' => ['label' => 'Last month', 'group' => 'day'],
            'this_year' => ['label' => 'This year', 'group' => 'month'],
        ];
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

    private function formatMargin(?float $margin): string
    {
        return is_null($margin) ? 'No sales' : $margin.'%';
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
