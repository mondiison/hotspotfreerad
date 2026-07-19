<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->filteredPayments($request, $filters);

        $summaryQuery = clone $query;
        $successfulQuery = (clone $summaryQuery)->where('status', 'successful');
        $pendingQuery = (clone $summaryQuery)->where('status', 'pending');
        $failedQuery = (clone $summaryQuery)->whereIn('status', ['failed', 'verification_failed']);

        return view('admin.payments.index', [
            'payments' => $query->latest()->paginate(20)->withQueryString(),
            'filters' => [
                'preset' => $filters['preset'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'search' => $filters['search'],
                'status' => $filters['status'],
                'provider' => $filters['provider'],
            ],
            'presets' => $this->presets(),
            'summary' => [
                'count' => (clone $summaryQuery)->count(),
                'successful_count' => (clone $summaryQuery)->where('status', 'successful')->count(),
                'pending_count' => (clone $summaryQuery)->where('status', 'pending')->count(),
                'failed_count' => (clone $summaryQuery)->whereIn('status', ['failed', 'verification_failed'])->count(),
                'successful_revenue' => (clone $successfulQuery)->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)')),
                'pending_value' => (clone $pendingQuery)->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)')),
                'failed_value' => (clone $failedQuery)->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)')),
                'platform_fee' => (clone $successfulQuery)->sum('platform_fee_amount'),
                'tenant_net' => (clone $successfulQuery)->sum(DB::raw('coalesce(nullif(tenant_net_amount, 0), coalesce(nullif(gross_amount, 0), amount) - platform_fee_amount)')),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'payments-'.$filters['from'].'-to-'.$filters['to'].'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Payment Report']);
            fputcsv($handle, ['From', $filters['from']]);
            fputcsv($handle, ['To', $filters['to']]);
            fputcsv($handle, ['Status', $filters['status'] ?: 'All']);
            fputcsv($handle, ['Provider', $filters['provider'] ?: 'All']);
            fputcsv($handle, ['Search', $filters['search'] ?: '']);
            fputcsv($handle, []);
            fputcsv($handle, [
                'Created At',
                'Paid At',
                'Transaction Ref',
                'Provider Ref',
                'Provider',
                'Status',
                'Customer MAC',
                'Customer Email',
                'Customer Phone',
                'Package',
                'Shop',
                'Tenant',
                'Currency',
                'Gross',
                'Platform Commission',
                'Tenant Net',
                'Commission Rate',
                'Billing Model',
                'Provisioned',
            ]);

            $this->filteredPayments($request, $filters)
                ->latest()
                ->chunk(200, function ($payments) use ($handle): void {
                    foreach ($payments as $payment) {
                        fputcsv($handle, [
                            $payment->created_at?->toDateTimeString(),
                            $payment->paid_at?->toDateTimeString(),
                            $payment->tx_ref,
                            $payment->provider_reference,
                            $payment->provider,
                            $payment->status,
                            $payment->customer?->mac_address ?? data_get($payment->payload, 'mac'),
                            $payment->customer?->email,
                            $payment->customer?->phone,
                            $payment->package?->name ?? 'Deleted package',
                            $payment->shop?->name ?? 'Deleted shop',
                            $payment->shop?->tenant?->company_name,
                            $payment->currency,
                            number_format($payment->gross_amount ?: $payment->amount, 2, '.', ''),
                            number_format($payment->platform_fee_amount, 2, '.', ''),
                            number_format($payment->tenant_net_amount ?: ($payment->gross_amount ?: $payment->amount), 2, '.', ''),
                            number_format((float) $payment->commission_rate, 2, '.', ''),
                            $payment->billing_model ?? 'subscription',
                            $payment->subscription ? 'Yes' : 'No',
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function filteredPayments(Request $request, array $filters)
    {
        return TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package', 'customer', 'subscription']),
            $request->user()
        )
            ->whereBetween('created_at', [$filters['from_date'], $filters['to_date']])
            ->when(filled($filters['search']), function ($query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('tx_ref', 'like', "%{$search}%")
                        ->orWhere('provider_reference', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('mac_address', 'like', "%{$search}%"))
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('package', fn ($package) => $package->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(filled($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['provider']), fn ($query) => $query->where('provider', $filters['provider']));
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'preset' => ['nullable', 'in:today,last_7_days,this_month,last_month,this_year'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,successful,failed,verification_failed'],
            'provider' => ['nullable', 'in:flutterwave'],
        ]);

        $preset = $data['preset'] ?? null;

        if ($preset) {
            [$from, $to] = $this->presetRange($preset);
        } else {
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
            'search' => $data['search'] ?? null,
            'status' => $data['status'] ?? null,
            'provider' => $data['provider'] ?? null,
        ];
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
}
