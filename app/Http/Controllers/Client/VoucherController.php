<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\VoucherBatch;
use App\Support\ClientAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Phase 2 self-service voucher management -- index() is a thin wrapper
 * around App\Livewire\Client\VouchersIndex (mirroring PackageController's
 * shape); print() reuses the EXACT same admin.vouchers.print Blade view
 * (a standalone, access-logic-free print template -- see that view's own
 * plain <form method="GET"> with no hardcoded route name) rather than
 * duplicating it, with ClientAccess::assertVoucherBatch() as the real gate
 * instead of the admin route's TenantAccess check (too permissive for a
 * Client -- it would let them print another shop's batch in the same
 * tenant). CSV export is left admin-only for this phase; print covers the
 * actual physical hand-out-vouchers use case.
 */
class VoucherController extends Controller
{
    public function index(): View
    {
        return view('client.vouchers.index');
    }

    public function print(Request $request, VoucherBatch $voucherBatch): View
    {
        ClientAccess::assertVoucherBatch($voucherBatch, $request->user());

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
}
