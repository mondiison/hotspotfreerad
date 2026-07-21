<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionController extends Controller
{
    public function index(Request $request, SubscriptionReportService $reports): View
    {
        $filters = $reports->filters($request->query());

        return view('admin.subscriptions.index', [
            'filters' => $filters,
        ]);
    }

    public function export(Request $request, SubscriptionReportService $reports): StreamedResponse
    {
        $filters = $reports->filters($request->query());
        $filename = 'access-report-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request, $reports, $filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Access Report']);
            fputcsv($handle, ['From', $filters['from'] ?: 'All']);
            fputcsv($handle, ['To', $filters['to'] ?: 'All']);
            fputcsv($handle, ['Status', $filters['status'] ?: 'All']);
            fputcsv($handle, ['Source', $filters['source'] ?: 'All']);
            fputcsv($handle, ['Throttled', $filters['throttled'] === '1' ? 'Yes' : 'All']);
            fputcsv($handle, ['Search', $filters['search'] ?: '']);
            fputcsv($handle, []);
            fputcsv($handle, [
                'MAC Address',
                'Package',
                'Shop',
                'Tenant',
                'Source',
                'Payment Ref',
                'Status',
                'Started At',
                'Expires At',
                'Throttled',
                'Speed Profile',
                'Upload Bytes',
                'Download Bytes',
                'Total Transfer Bytes',
                'RADIUS Sessions',
                'Created At',
            ]);

            $reports->query($request->user(), $filters)
                ->latest('expires_at')
                ->chunk(200, function ($subscriptions) use ($handle, $reports): void {
                    $reports->attachUsage($subscriptions);

                    foreach ($subscriptions as $subscription) {
                        $isActive = $subscription->expires_at->isFuture();
                        $usage = $subscription->radius_usage;

                        fputcsv($handle, [
                            $subscription->mac_address,
                            $subscription->package?->name ?? 'Deleted package',
                            $subscription->shop?->name ?? 'Deleted shop',
                            $subscription->shop?->tenant?->company_name,
                            $subscription->payment ? 'Paid' : 'Test',
                            $subscription->payment?->tx_ref,
                            $isActive ? 'Active' : 'Expired',
                            $subscription->starts_at?->toDateTimeString(),
                            $subscription->expires_at?->toDateTimeString(),
                            $subscription->is_throttled ? 'Yes' : 'No',
                            $subscription->package?->speed_limit_profile,
                            $usage['upload_bytes'],
                            $usage['download_bytes'],
                            $usage['total_bytes'],
                            $usage['session_count'],
                            $subscription->created_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
