<?php

namespace App\Livewire\Admin;

use App\Models\Package;
use App\Models\Shop;
use App\Models\VoucherBatch;
use App\Services\VoucherManagementService;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class VouchersIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public bool $showGenerateModal = false;

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
    ];

    public function mount(array $filters = []): void
    {
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
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

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
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
            ->when($this->status === 'active', fn ($query) => $query->where('status', 'active'))
            ->when($this->status === 'exhausted', fn ($query) => $query->has('vouchers')->whereDoesntHave('vouchers', fn ($voucher) => $voucher->where('status', 'unused')));

        return view('livewire.admin.vouchers-index', [
            'batches' => $query->latest()->paginate(12),
            'summary' => $this->summary(),
            'shops' => $this->shops(),
            'packages' => $this->packages(),
        ]);
    }

    private function summary(): array
    {
        $query = TenantAccess::scopeVouchers(\App\Models\Voucher::query(), auth()->user());

        return [
            'total' => (clone $query)->count(),
            'unused' => (clone $query)->where('status', 'unused')->count(),
            'used' => (clone $query)->where('status', 'used')->count(),
            'used_this_month' => (clone $query)
                ->where('status', 'used')
                ->whereBetween('used_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
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
            'status' => ['nullable', Rule::in(['active', 'exhausted'])],
        ])->validate();
    }
}
