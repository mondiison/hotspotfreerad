<?php

namespace App\Livewire\Admin;

use App\Models\Package;
use App\Models\Shop;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherManagementService;
use App\Support\TenantAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class VouchersIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $shop = '';

    public string $used_from = '';

    public string $used_to = '';

    public bool $showGenerateModal = false;

    public bool $showInspectModal = false;

    public ?int $selectedBatchId = null;

    public string $shop_id = '';

    public string $package_id = '';

    public string $name = '';

    public string $quantity = '20';

    public string $code_length = '8';

    public string $prefix = '';

    public string $notes = '';

    public ?string $savedMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'shop' => ['except' => ''],
        'used_from' => ['except' => ''],
        'used_to' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->shop = (string) ($filters['shop'] ?? '');
        $this->used_from = (string) ($filters['used_from'] ?? '');
        $this->used_to = (string) ($filters['used_to'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'shop', 'used_from', 'used_to'], true)) {
            $this->resetPage();
        }
    }

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
        $data = $this->validate($vouchers->rules(auth()->user()));
        $batch = $vouchers->createBatch($data, auth()->user());

        $this->savedMessage = $batch->quantity.' vouchers generated for '.$batch->name.'.';
        $this->showGenerateModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function filterBy(string $status = ''): void
    {
        $this->search = '';
        $this->status = $status;
        $this->resetPage();
    }

    public function filterUsedThisMonth(): void
    {
        $this->search = '';
        $this->status = 'used';
        $this->used_from = now()->startOfMonth()->toDateString();
        $this->used_to = now()->endOfMonth()->toDateString();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'shop', 'used_from', 'used_to']);
        $this->resetPage();
    }

    public function inspect(int $batchId): void
    {
        $batch = VoucherBatch::with('shop')->findOrFail($batchId);
        TenantAccess::assertVoucherBatch($batch, auth()->user());

        $this->selectedBatchId = $batch->id;
        $this->showInspectModal = true;
    }

    public function closeInspect(): void
    {
        $this->showInspectModal = false;
        $this->selectedBatchId = null;
    }

    public function render()
    {
        $this->validateOnlyFilters();

        $query = TenantAccess::scopeVoucherBatches(
            VoucherBatch::with(['shop.tenant', 'package'])->withCount([
                'vouchers',
                'vouchers as used_vouchers_count' => fn ($query) => $query->where('status', 'used'),
                'vouchers as unused_vouchers_count' => fn ($query) => $query->where('status', 'unused'),
            ]),
            auth()->user()
        )
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('prefix', 'like', "%{$this->search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$this->search}%"))
                        ->orWhereHas('package', fn ($package) => $package->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->shop, fn ($query) => $query->where('shop_id', $this->shop))
            ->when($this->status === 'active', fn ($query) => $query->where('status', 'active'))
            ->when($this->status === 'exhausted', fn ($query) => $query->has('vouchers')->whereDoesntHave('vouchers', fn ($voucher) => $voucher->where('status', 'unused')))
            ->when(in_array($this->status, ['used', 'unused'], true), fn ($query) => $query->whereHas('vouchers', fn ($voucher) => $voucher->where('status', $this->status)))
            ->when($this->used_from || $this->used_to, fn ($query) => $query->whereHas('vouchers', fn ($voucher) => $this->applyUsedDateFilters($voucher)));

        return view('livewire.admin.vouchers-index', [
            'batches' => $query->latest()->paginate(12),
            'selectedBatch' => $this->selectedBatch(),
            'summary' => $this->summary(),
            'shops' => $this->shops(),
            'packages' => $this->packages(),
            'exportQuery' => $this->exportQuery(),
        ]);
    }

    private function summary(): array
    {
        $query = TenantAccess::scopeVouchers(Voucher::query(), auth()->user())
            ->when($this->shop, fn ($query) => $query->where('shop_id', $this->shop));

        $filteredQuery = (clone $query)
            ->when($this->used_from || $this->used_to, fn ($query) => $this->applyUsedDateFilters($query));

        return [
            'total' => (clone $query)->count(),
            'unused' => (clone $query)->where('status', 'unused')->count(),
            'used' => (clone $query)->where('status', 'used')->count(),
            'used_this_month' => (clone $query)
                ->where('status', 'used')
                ->whereBetween('used_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'used_in_filter' => (clone $filteredQuery)->where('status', 'used')->count(),
        ];
    }

    private function shops(): Collection
    {
        return TenantAccess::scopeShops(Shop::with('tenant'), auth()->user())->orderBy('name')->get();
    }

    private function packages(): Collection
    {
        $query = TenantAccess::scopePackages(Package::with('shop.tenant'), auth()->user())
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
            'shop.tenant',
            'package',
            'vouchers' => fn ($query) => $query
                ->with('subscription')
                ->orderByRaw("case when status = 'unused' then 0 else 1 end")
                ->orderBy('code'),
        ])
            ->withCount([
                'vouchers',
                'vouchers as used_vouchers_count' => fn ($query) => $query->where('status', 'used'),
                'vouchers as unused_vouchers_count' => fn ($query) => $query->where('status', 'unused'),
            ])
            ->find($this->selectedBatchId);

        if (! $batch) {
            return null;
        }

        TenantAccess::assertVoucherBatch($batch, auth()->user());

        return $batch;
    }

    private function exportQuery(): array
    {
        return array_filter([
            'search' => $this->search,
            'status' => $this->status,
            'shop' => $this->shop,
            'used_from' => $this->used_from,
            'used_to' => $this->used_to,
        ], fn ($value): bool => filled($value));
    }

    private function resetForm(): void
    {
        $this->reset(['shop_id', 'package_id', 'name', 'quantity', 'code_length', 'prefix', 'notes']);
        $this->quantity = '20';
        $this->code_length = '8';
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator(['status' => $this->status ?: null], [
            'status' => ['nullable', Rule::in(['active', 'exhausted', 'used', 'unused'])],
        ])->validate();

        validator([
            'shop' => $this->shop ?: null,
            'used_from' => $this->used_from ?: null,
            'used_to' => $this->used_to ?: null,
        ], [
            'shop' => ['nullable', TenantAccess::shopExistsRule(auth()->user())],
            'used_from' => ['nullable', 'date'],
            'used_to' => ['nullable', 'date', 'after_or_equal:used_from'],
        ])->validate();
    }

    private function applyUsedDateFilters(Builder $query): Builder
    {
        return $query
            ->where('status', 'used')
            ->when($this->used_from, fn ($query) => $query->where('used_at', '>=', $this->used_from.' 00:00:00'))
            ->when($this->used_to, fn ($query) => $query->where('used_at', '<=', $this->used_to.' 23:59:59'));
    }
}
