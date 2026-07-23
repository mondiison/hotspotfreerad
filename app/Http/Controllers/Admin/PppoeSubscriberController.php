<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PppoeSubscriber;
use App\Services\PppoeSubscriberReportService;
use App\Support\TenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PppoeSubscriberController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.pppoe-subscribers.index', [
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function export(Request $request, PppoeSubscriberReportService $reports): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'pppoe-customers-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request, $reports, $filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['PPPoE Customers']);
            fputcsv($handle, ['Status', $filters['status'] ?: 'All']);
            fputcsv($handle, ['Search', $filters['search'] ?: '']);
            fputcsv($handle, []);
            fputcsv($handle, [
                'Username',
                'Customer Name',
                'Phone',
                'Email',
                'Package',
                'Shop',
                'Tenant',
                'Status',
                'Started At',
                'Expires At',
                'Last Provisioned At',
                'Speed Profile',
                'Upload Bytes',
                'Download Bytes',
                'Total Transfer Bytes',
                'Online Sessions',
                'RADIUS Sessions',
                'Last Seen At',
                'Created At',
            ]);

            $this->query($request, $filters)
                ->latest()
                ->chunk(200, function ($subscribers) use ($handle, $reports): void {
                    $reports->attachUsage($subscribers);

                    foreach ($subscribers as $subscriber) {
                        $usage = $subscriber->radius_usage;

                        fputcsv($handle, [
                            $subscriber->username,
                            $subscriber->full_name,
                            $subscriber->phone,
                            $subscriber->email,
                            $subscriber->package?->name ?? 'Deleted package',
                            $subscriber->shop?->name ?? 'Deleted shop',
                            $subscriber->shop?->tenant?->company_name,
                            $this->statusLabel($subscriber),
                            $subscriber->starts_at?->toDateTimeString(),
                            $subscriber->expires_at?->toDateTimeString(),
                            $subscriber->last_provisioned_at?->toDateTimeString(),
                            $subscriber->package?->speed_limit_profile,
                            $usage['upload_bytes'],
                            $usage['download_bytes'],
                            $usage['total_bytes'],
                            $usage['open_session_count'],
                            $usage['session_count'],
                            $usage['last_seen_at']?->toDateTimeString(),
                            $subscriber->created_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        $filters = validator($request->query(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'expiring_soon', 'expired', 'disabled', 'unsynced'])],
        ])->validate();

        return [
            'search' => $filters['search'] ?? null,
            'status' => $filters['status'] ?? null,
        ];
    }

    private function query(Request $request, array $filters)
    {
        return TenantAccess::scopePppoeSubscribers(PppoeSubscriber::with(['shop.tenant', 'package']), $request->user())
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('username', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->when(($filters['status'] ?? null) === 'expiring_soon', fn ($query) => $query->where('is_active', true)->whereBetween('expires_at', [now(), now()->addDays(7)]))
            ->when(($filters['status'] ?? null) === 'expired', fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->when(($filters['status'] ?? null) === 'disabled', fn ($query) => $query->where('is_active', false))
            ->when(($filters['status'] ?? null) === 'unsynced', fn ($query) => $query->whereNull('last_provisioned_at'));
    }

    private function statusLabel(PppoeSubscriber $subscriber): string
    {
        return match (true) {
            ! $subscriber->is_active => 'Disabled',
            $subscriber->expires_at && $subscriber->expires_at->isPast() => 'Expired',
            $subscriber->expires_at && $subscriber->expires_at->lessThanOrEqualTo(now()->addDays(7)) => 'Due soon',
            default => 'Active',
        };
    }
}
