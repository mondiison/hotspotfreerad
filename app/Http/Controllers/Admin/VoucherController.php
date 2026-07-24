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
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function print(Request $request, VoucherBatch $voucherBatch): View
    {
        TenantAccess::assertVoucherBatch($voucherBatch, $request->user());

        return view('admin.vouchers.print', [
            'batch' => $voucherBatch->load(['shop.tenant', 'package', 'vouchers' => fn ($query) => $query->orderBy('code')]),
        ]);
    }
}
