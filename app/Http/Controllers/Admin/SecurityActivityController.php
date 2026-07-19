<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SecurityActivityReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityActivityController extends Controller
{
    public function export(Request $request, SecurityActivityReportService $reports): StreamedResponse
    {
        $filters = $reports->filters($request->query());
        $filename = 'security-activity-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request, $reports, $filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Security Activity Report']);
            fputcsv($handle, ['Date Range', $reports->dateLabel($filters['date_preset'])]);
            fputcsv($handle, ['Event Group', $reports->groupLabel($filters['action_group'])]);
            fputcsv($handle, ['Event Reason', $reports->actionLabel($filters['action'])]);
            fputcsv($handle, ['Attention Only', $filters['attention'] === '1' ? 'Yes' : 'No']);
            fputcsv($handle, ['Search', $filters['search']]);
            fputcsv($handle, []);
            fputcsv($handle, [
                'Created At',
                'Event',
                'Action',
                'Priority',
                'Admin Name',
                'Admin Email',
                'Tenant',
                'IP Address',
                'User Agent',
                'Metadata',
            ]);

            $reports->query($request->user(), $filters)
                ->latest()
                ->chunk(200, function ($activities) use ($handle, $reports): void {
                    foreach ($activities as $activity) {
                        fputcsv($handle, [
                            $activity->created_at?->toDateTimeString(),
                            $activity->label,
                            $activity->action,
                            $reports->priorityLabel($activity->action),
                            $activity->user?->name ?? 'Deleted user',
                            $activity->user?->email,
                            $activity->tenant?->company_name ?? 'Platform',
                            $activity->ip_address,
                            $activity->user_agent,
                            json_encode($activity->metadata ?? []),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
