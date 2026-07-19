<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function index(Request $request, PaymentReportService $reports): View
    {
        $filters = $reports->filters($request->query());

        return view('admin.payments.index', [
            'filters' => [
                'preset' => $filters['preset'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'search' => $filters['search'],
                'status' => $filters['status'],
                'provider' => $filters['provider'],
            ],
        ]);
    }

    public function export(Request $request, PaymentReportService $reports): StreamedResponse
    {
        $filters = $reports->filters($request->query());
        $filename = 'payments-'.$filters['from'].'-to-'.$filters['to'].'.csv';

        return response()->streamDownload(function () use ($request, $reports, $filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Payment Report']);
            fputcsv($handle, ['From', $filters['from']]);
            fputcsv($handle, ['To', $filters['to']]);
            fputcsv($handle, ['Status', $reports->statusLabel($filters['status'])]);
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

            $reports->query($request->user(), $filters)
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
}
