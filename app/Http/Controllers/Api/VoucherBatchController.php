<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\VoucherBatchResource;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherManagementService;
use App\Support\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin JSON wrapper over VoucherManagementService -- every method here
 * calls the exact same service method the web admin's VouchersIndex
 * Livewire component already calls, so a voucher batch created from the
 * app is indistinguishable from one created on the web (same validation
 * via VoucherManagementService::rules(), same RADIUS-free "just rows"
 * creation, same reports it shows up in).
 */
class VoucherBatchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'shop_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:active,void'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $batches = TenantAccess::scopeVoucherBatches(VoucherBatch::query(), $request->user())
            ->with(['shop', 'package'])
            ->when($validated['shop_id'] ?? null, fn ($query, $shopId) => $query->where('shop_id', $shopId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->latest()
            ->paginate(20);

        return VoucherBatchResource::collection($batches);
    }

    public function store(Request $request, VoucherManagementService $vouchers): JsonResponse
    {
        $data = $request->validate($vouchers->rules($request->user()));

        $batch = $vouchers->createBatch($data, $request->user());

        return (new VoucherBatchResource($batch->load(['shop', 'package'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, VoucherBatch $voucherBatch): VoucherBatchResource
    {
        TenantAccess::assertVoucherBatch($voucherBatch, $request->user());

        return new VoucherBatchResource($voucherBatch->load(['shop', 'package', 'vouchers' => fn ($query) => $query->orderBy('code')]));
    }

    public function voidUnused(Request $request, VoucherBatch $voucherBatch, VoucherManagementService $vouchers): JsonResponse
    {
        $voided = $vouchers->voidUnusedBatchVouchers($voucherBatch, $request->user());

        return response()->json(['voided' => $voided]);
    }

    public function markVoucherSold(Request $request, Voucher $voucher, VoucherManagementService $vouchers): JsonResponse
    {
        $data = $request->validate([
            'sale_amount' => ['nullable', 'numeric', 'min:0'],
            'sale_reference' => ['nullable', 'string', 'max:255'],
            'sale_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $vouchers->markSold($voucher, $data, $request->user());

        return response()->json(['message' => 'Voucher marked as sold.']);
    }
}
