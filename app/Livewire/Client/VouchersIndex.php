<?php

namespace App\Livewire\Client;

use App\Models\Package;
use App\Models\Shop;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherManagementService;
use App\Support\ClientAccess;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 2 (2026-11-12): a Client's own self-service voucher management --
 * a leaner sibling of Admin\VouchersIndex (no search/date-range filters,
 * no sales-breakdown analytics -- covered separately by the Client
 * Reports page instead). Every write still funnels through the existing
 * VoucherManagementService; ClientAccess scoping gates it the same way
 * Client\PackagesIndex does.
 */
class VouchersIndex extends Component
{
    use WithPagination;

    public string $status = '';

    public bool $showGenerateModal = false;

    public bool $showInspectModal = false;

    public bool $showVoidModal = false;

    public bool $showSaleModal = false;

    public bool $showReverseSaleModal = false;

    public ?int $selectedBatchId = null;

    public ?int $voidBatchId = null;

    public ?int $saleVoucherId = null;

    public ?int $reverseSaleVoucherId = null;

    public string $sale_amount = '';

    public string $sale_reference = '';

    public string $sale_notes = '';

    public string $shop_id = '';

    public string $package_id = '';

    public string $name = '';

    public string $quantity = '20';

    public string $code_length = '8';

    public string $prefix = '';

    public string $notes = '';

    public ?string $savedMessage = null;

    public function updatedShopId(): void
    {
        $this->package_id = '';
    }

    public function create(): void
    {
        $this->resetForm();
        $firstShop = $this->shops()->first();
        $this->shop_id = $firstShop ? (string) $firstShop->id : '';
        $this->name = 'Voucher batch '.now()->format('M j, Y');
        $this->showGenerateModal = true;
    }

    public function save(VoucherManagementService $vouchers): void
    {
        $rules = $vouchers->rules(auth()->user());
        $rules['shop_id'] = ['required', ClientAccess::shopExistsRule(auth()->user())];

        $data = $this->validate($rules);
        $batch = $vouchers->createBatch($data, auth()->user());

        $this->savedMessage = $batch->quantity.' vouchers generated for '.$batch->name.'.';
        $this->showGenerateModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function filterBy(string $status = ''): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['status']);
        $this->resetPage();
    }

    public function inspect(int $batchId): void
    {
        $batch = VoucherBatch::with('shop')->findOrFail($batchId);
        ClientAccess::assertVoucherBatch($batch, auth()->user());

        $this->selectedBatchId = $batch->id;
        $this->showInspectModal = true;
    }

    public function closeInspect(): void
    {
        $this->showInspectModal = false;
        $this->selectedBatchId = null;
    }

    public function confirmVoid(int $batchId): void
    {
        $batch = VoucherBatch::with('shop')->findOrFail($batchId);
        ClientAccess::assertVoucherBatch($batch, auth()->user());

        $this->voidBatchId = $batch->id;
        $this->showVoidModal = true;
    }

    public function voidUnused(VoucherManagementService $vouchers): void
    {
        if (! $this->voidBatchId) {
            return;
        }

        $batch = VoucherBatch::findOrFail($this->voidBatchId);
        ClientAccess::assertVoucherBatch($batch, auth()->user());
        $voided = $vouchers->voidUnusedBatchVouchers($batch, auth()->user());

        $this->savedMessage = "{$voided} unused voucher(s) voided from {$batch->name}.";
        $this->showVoidModal = false;
        $this->voidBatchId = null;
        $this->selectedBatchId = $batch->id;
    }

    public function confirmSale(int $voucherId): void
    {
        $voucher = Voucher::with(['shop', 'package'])->findOrFail($voucherId);
        ClientAccess::assertVoucher($voucher, auth()->user());

        if ($voucher->status !== 'unused') {
            $this->addError('sale_voucher_id', 'Only unused vouchers can be marked as sold.');

            return;
        }

        $this->saleVoucherId = $voucher->id;
        $this->sale_amount = (string) ($voucher->package?->price ?? '');
        $this->sale_reference = '';
        $this->sale_notes = '';
        $this->showSaleModal = true;
        $this->resetValidation(['sale_amount', 'sale_reference', 'sale_notes', 'sale_voucher_id']);
    }

    public function markSold(VoucherManagementService $vouchers): void
    {
        $validated = $this->validate([
            'sale_amount' => ['required', 'numeric', 'min:0'],
            'sale_reference' => ['nullable', 'string', 'max:255'],
            'sale_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->saleVoucherId) {
            return;
        }

        $voucher = Voucher::findOrFail($this->saleVoucherId);
        ClientAccess::assertVoucher($voucher, auth()->user());
        $vouchers->markSold($voucher, $validated, auth()->user());

        $this->savedMessage = "{$voucher->code} marked as sold.";
        $this->selectedBatchId = $voucher->voucher_batch_id;
        $this->showSaleModal = false;
        $this->saleVoucherId = null;
        $this->reset(['sale_amount', 'sale_reference', 'sale_notes']);
    }

    public function confirmReverseSale(int $voucherId): void
    {
        $voucher = Voucher::with('shop')->findOrFail($voucherId);
        ClientAccess::assertVoucher($voucher, auth()->user());

        if ($voucher->status !== 'sold') {
            $this->addError('reverse_sale_voucher_id', 'Only sold and unredeemed vouchers can have their sale reversed.');

            return;
        }

        $this->reverseSaleVoucherId = $voucher->id;
        $this->showReverseSaleModal = true;
        $this->resetValidation('reverse_sale_voucher_id');
    }

    public function reverseSale(VoucherManagementService $vouchers): void
    {
        if (! $this->reverseSaleVoucherId) {
            return;
        }

        $voucher = Voucher::findOrFail($this->reverseSaleVoucherId);
        ClientAccess::assertVoucher($voucher, auth()->user());
        $vouchers->reverseSale($voucher, auth()->user());

        $this->savedMessage = "{$voucher->code} sale reversed and returned to unused.";
        $this->selectedBatchId = $voucher->voucher_batch_id;
        $this->showReverseSaleModal = false;
        $this->reverseSaleVoucherId = null;
    }

    public function render()
    {
        $query = ClientAccess::scopeVoucherBatches(
            VoucherBatch::with(['shop', 'package'])->withCount([
                'vouchers',
                'vouchers as used_vouchers_count' => fn ($query) => $query->where('status', 'used'),
                'vouchers as unused_vouchers_count' => fn ($query) => $query->where('status', 'unused'),
                'vouchers as sold_vouchers_count' => fn ($query) => $query->where('status', 'sold'),
                'vouchers as void_vouchers_count' => fn ($query) => $query->where('status', 'void'),
            ]),
            auth()->user()
        )
            ->when($this->status === 'active', fn ($query) => $query->where('status', 'active'))
            ->when($this->status === 'void', fn ($query) => $query->where('status', 'void'))
            ->when(in_array($this->status, ['used', 'unused', 'sold'], true), fn ($query) => $query->whereHas('vouchers', fn ($voucher) => $voucher->where('status', $this->status)));

        return view('livewire.client.vouchers-index', [
            'batches' => $query->latest()->paginate(10),
            'selectedBatch' => $this->selectedBatch(),
            'summary' => $this->summary(),
            'shops' => $this->shops(),
            'packages' => $this->packages(),
        ]);
    }

    private function summary(): array
    {
        $query = ClientAccess::scopeVouchers(Voucher::query(), auth()->user());

        return [
            'total' => (clone $query)->count(),
            'unused' => (clone $query)->where('status', 'unused')->count(),
            'sold' => (clone $query)->where('status', 'sold')->count(),
            'used' => (clone $query)->where('status', 'used')->count(),
            'void' => (clone $query)->where('status', 'void')->count(),
        ];
    }

    private function shops(): Collection
    {
        return ClientAccess::scopeShops(Shop::query(), auth()->user())->orderBy('name')->get();
    }

    private function packages(): Collection
    {
        $query = ClientAccess::scopePackages(Package::query(), auth()->user())
            ->where('is_active', true)
            ->whereIn('service_type', ['hotspot', 'both'])
            ->orderBy('name');

        if ($this->shop_id) {
            $query->where('shop_id', $this->shop_id);
        }

        return $query->get();
    }

    private function selectedBatch(): ?VoucherBatch
    {
        if (! $this->selectedBatchId) {
            return null;
        }

        $batch = VoucherBatch::with([
            'shop',
            'package',
            'vouchers' => fn ($query) => $query
                ->orderByRaw("case when status = 'unused' then 0 else 1 end")
                ->orderBy('code'),
        ])
            ->withCount([
                'vouchers',
                'vouchers as used_vouchers_count' => fn ($query) => $query->where('status', 'used'),
                'vouchers as unused_vouchers_count' => fn ($query) => $query->where('status', 'unused'),
                'vouchers as sold_vouchers_count' => fn ($query) => $query->where('status', 'sold'),
                'vouchers as void_vouchers_count' => fn ($query) => $query->where('status', 'void'),
            ])
            ->find($this->selectedBatchId);

        if (! $batch) {
            return null;
        }

        ClientAccess::assertVoucherBatch($batch, auth()->user());

        return $batch;
    }

    private function resetForm(): void
    {
        $this->reset(['shop_id', 'package_id', 'name', 'quantity', 'code_length', 'prefix', 'notes']);
        $this->quantity = '20';
        $this->code_length = '8';
        $this->resetValidation();
    }
}
