<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\TenantAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoucherController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.vouchers.index', [
            'filters' => $request->only(['search', 'status', 'shop', 'used_from', 'used_to', 'sold_from', 'sold_to']),
        ]);
    }

    public function print(Request $request, VoucherBatch $voucherBatch): View
    {
        TenantAccess::assertVoucherBatch($voucherBatch, $request->user());

        $validated = $request->validate([
            'columns' => ['nullable', 'integer', 'min:2', 'max:5'],
            'status' => ['nullable', 'string', 'in:all,unused,sold,used,void'],
        ]);

        $columns = (int) ($validated['columns'] ?? 3);
        $printStatus = (string) ($validated['status'] ?? 'all');

        return view('admin.vouchers.print', [
            'batch' => $voucherBatch->load([
                'shop.tenant',
                'package',
                'vouchers' => fn ($query) => $query
                    ->when($printStatus !== 'all', fn ($query) => $query->where('status', $printStatus))
                    ->orderBy('code'),
            ]),
            'columns' => $columns,
            'printStatus' => $printStatus,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'voucher-report-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Voucher Report']);
            fputcsv($handle, ['Search', $filters['search'] ?: '']);
            fputcsv($handle, ['Status', $filters['status'] ?: 'All']);
            fputcsv($handle, ['Shop', $filters['shop'] ?: 'All']);
            fputcsv($handle, ['Used From', $filters['used_from'] ?: 'All']);
            fputcsv($handle, ['Used To', $filters['used_to'] ?: 'All']);
            fputcsv($handle, ['Sold From', $filters['sold_from'] ?: 'All']);
            fputcsv($handle, ['Sold To', $filters['sold_to'] ?: 'All']);
            fputcsv($handle, []);
            fputcsv($handle, [
                'Code',
                'Batch',
                'Voucher Status',
                'Shop',
                'Tenant',
                'Package',
                'Speed Profile',
                'Uptime Seconds',
                'Transfer Limit Bytes',
                'Sale Amount',
                'Sale Reference',
                'Sold At',
                'Sold By',
                'Used MAC Address',
                'Used At',
                'Access Expires At',
                'Created At',
            ]);

            $this->voucherQuery($request, $filters)
                ->orderByDesc('created_at')
                ->chunk(300, function ($vouchers) use ($handle): void {
                    foreach ($vouchers as $voucher) {
                        fputcsv($handle, $this->voucherCsvRow($voucher));
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportBatch(Request $request, VoucherBatch $voucherBatch): StreamedResponse
    {
        TenantAccess::assertVoucherBatch($voucherBatch, $request->user());

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:all,unused,sold,used,void'],
        ]);
        $status = (string) ($validated['status'] ?? 'all');
        $filename = str($voucherBatch->name)->slug()->append('-vouchers-'.now()->format('Y-m-d-His').'.csv')->toString();

        return response()->streamDownload(function () use ($voucherBatch, $status): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Voucher Batch']);
            fputcsv($handle, ['Batch', $voucherBatch->name]);
            fputcsv($handle, ['Status Filter', $status]);
            fputcsv($handle, []);
            fputcsv($handle, [
                'Code',
                'Batch',
                'Voucher Status',
                'Shop',
                'Tenant',
                'Package',
                'Speed Profile',
                'Uptime Seconds',
                'Transfer Limit Bytes',
                'Sale Amount',
                'Sale Reference',
                'Sold At',
                'Sold By',
                'Used MAC Address',
                'Used At',
                'Access Expires At',
                'Created At',
            ]);

            $voucherBatch->vouchers()
                ->with(['batch', 'shop.tenant', 'package', 'subscription', 'soldBy'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->orderBy('code')
                ->chunk(300, function ($vouchers) use ($handle): void {
                    foreach ($vouchers as $voucher) {
                        fputcsv($handle, $this->voucherCsvRow($voucher));
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function voucherQuery(Request $request, array $filters): Builder
    {
        return TenantAccess::scopeVouchers(
            Voucher::with(['batch', 'shop.tenant', 'package', 'subscription', 'soldBy']),
            $request->user()
        )
            ->when($filters['shop'], fn ($query) => $query->where('shop_id', $filters['shop']))
            ->when($filters['status'] === 'used', fn ($query) => $query->where('status', 'used'))
            ->when($filters['status'] === 'unused', fn ($query) => $query->where('status', 'unused'))
            ->when($filters['status'] === 'sold', fn ($query) => $query->where('status', 'sold'))
            ->when($filters['status'] === 'void', fn ($query) => $query->where('status', 'void'))
            ->when($filters['status'] === 'active', fn ($query) => $query->whereHas('batch', fn ($batch) => $batch->where('status', 'active')))
            ->when($filters['status'] === 'exhausted', fn ($query) => $query->whereHas('batch', fn ($batch) => $batch->has('vouchers')->whereDoesntHave('vouchers', fn ($voucher) => $voucher->whereIn('status', ['unused', 'sold']))))
            ->when($filters['used_from'] || $filters['used_to'], function ($query) use ($filters): void {
                $query
                    ->where('status', 'used')
                    ->when($filters['used_from'], fn ($query) => $query->where('used_at', '>=', $filters['used_from'].' 00:00:00'))
                    ->when($filters['used_to'], fn ($query) => $query->where('used_at', '<=', $filters['used_to'].' 23:59:59'));
            })
            ->when($filters['sold_from'] || $filters['sold_to'], function ($query) use ($filters): void {
                $query
                    ->whereNotNull('sold_at')
                    ->when($filters['sold_from'], fn ($query) => $query->where('sold_at', '>=', $filters['sold_from'].' 00:00:00'))
                    ->when($filters['sold_to'], fn ($query) => $query->where('sold_at', '<=', $filters['sold_to'].' 23:59:59'));
            })
            ->when($filters['search'], function ($query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('code', 'like', "%{$search}%")
                        ->orWhereHas('batch', fn ($batch) => $batch
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('prefix', 'like', "%{$search}%"))
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('package', fn ($package) => $package->where('name', 'like', "%{$search}%"));
                });
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function voucherCsvRow(Voucher $voucher): array
    {
        return [
            $voucher->code,
            $voucher->batch?->name ?? 'Deleted batch',
            $voucher->status,
            $voucher->shop?->name ?? 'Deleted shop',
            $voucher->shop?->tenant?->company_name,
            $voucher->package?->name ?? 'Deleted package',
            $voucher->package?->speed_limit_profile,
            $voucher->package?->limit_uptime_seconds,
            $voucher->package?->data_limit_bytes,
            $voucher->sale_amount,
            $voucher->sale_reference,
            $voucher->sold_at?->toDateTimeString(),
            $voucher->soldBy?->name,
            $voucher->used_mac_address,
            $voucher->used_at?->toDateTimeString(),
            $voucher->subscription?->expires_at?->toDateTimeString(),
            $voucher->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array{search:string,status:string,shop:string,used_from:string,used_to:string,sold_from:string,sold_to:string}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,exhausted,used,unused,sold,void'],
            'shop' => ['nullable', TenantAccess::shopExistsRule($request->user())],
            'used_from' => ['nullable', 'date'],
            'used_to' => ['nullable', 'date', 'after_or_equal:used_from'],
            'sold_from' => ['nullable', 'date'],
            'sold_to' => ['nullable', 'date', 'after_or_equal:sold_from'],
        ]);

        return [
            'search' => (string) ($validated['search'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'shop' => (string) ($validated['shop'] ?? ''),
            'used_from' => (string) ($validated['used_from'] ?? ''),
            'used_to' => (string) ($validated['used_to'] ?? ''),
            'sold_from' => (string) ($validated['sold_from'] ?? ''),
            'sold_to' => (string) ($validated['sold_to'] ?? ''),
        ];
    }
}
