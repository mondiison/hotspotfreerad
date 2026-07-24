<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VoucherBatch;
use App\Support\TenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.vouchers.index', [
            'filters' => $request->only(['search', 'status', 'shop', 'used_from', 'used_to']),
        ]);
    }

    public function print(Request $request, VoucherBatch $voucherBatch): View
    {
        TenantAccess::assertVoucherBatch($voucherBatch, $request->user());

        $validated = $request->validate([
            'columns' => ['nullable', 'integer', 'min:2', 'max:5'],
            'status' => ['nullable', 'string', 'in:all,unused,used'],
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
