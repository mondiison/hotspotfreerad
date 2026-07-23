<?php

namespace App\Livewire\Admin;

use App\Models\Package;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Services\PppoeSubscriberManagementService;
use App\Services\PppoeSubscriberReportService;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class PppoeSubscribersIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public bool $showInspectModal = false;

    public ?int $editingSubscriberId = null;

    public ?int $deletingSubscriberId = null;

    public ?int $selectedSubscriberId = null;

    public string $shop_id = '';

    public string $package_id = '';

    public string $username = '';

    public string $password = '';

    public string $full_name = '';

    public string $phone = '';

    public string $email = '';

    public string $starts_at = '';

    public string $expires_at = '';

    public bool $is_active = true;

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

    public function create(PppoeSubscriberManagementService $subscribers): void
    {
        $this->resetForm();
        $firstShop = $this->shops()->first();
        $this->shop_id = $firstShop ? (string) $firstShop->id : '';
        $this->username = $firstShop ? $subscribers->generateUsername($firstShop->name) : '';
        $this->password = $subscribers->generatePassword();
        $this->starts_at = now()->format('Y-m-d\TH:i');
        $this->showFormModal = true;
    }

    public function edit(int $subscriberId): void
    {
        $subscriber = PppoeSubscriber::with(['shop', 'package'])->findOrFail($subscriberId);
        TenantAccess::assertPppoeSubscriber($subscriber, auth()->user());

        $this->editingSubscriberId = $subscriber->id;
        $this->shop_id = (string) $subscriber->shop_id;
        $this->package_id = (string) $subscriber->package_id;
        $this->username = (string) $subscriber->username;
        $this->password = '';
        $this->full_name = (string) $subscriber->full_name;
        $this->phone = (string) $subscriber->phone;
        $this->email = (string) $subscriber->email;
        $this->starts_at = $subscriber->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->expires_at = $subscriber->expires_at?->format('Y-m-d\TH:i') ?? '';
        $this->is_active = (bool) $subscriber->is_active;
        $this->showFormModal = true;
    }

    public function save(PppoeSubscriberManagementService $subscribers): void
    {
        $subscriber = $this->editingSubscriberId ? PppoeSubscriber::findOrFail($this->editingSubscriberId) : null;
        $data = $this->validate($subscribers->rules(auth()->user(), $subscriber));

        if ($subscriber) {
            $subscribers->update($subscriber, $data, auth()->user());
            $this->savedMessage = 'PPPoE subscriber updated and synced to RADIUS.';
        } else {
            $subscribers->create($data, auth()->user());
            $this->savedMessage = 'PPPoE subscriber created and synced to RADIUS.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $subscriberId): void
    {
        $subscriber = PppoeSubscriber::findOrFail($subscriberId);
        TenantAccess::assertPppoeSubscriber($subscriber, auth()->user());

        $this->deletingSubscriberId = $subscriber->id;
        $this->showDeleteModal = true;
    }

    public function delete(PppoeSubscriberManagementService $subscribers): void
    {
        if (! $this->deletingSubscriberId) {
            return;
        }

        $subscribers->delete(PppoeSubscriber::findOrFail($this->deletingSubscriberId), auth()->user());

        $this->showDeleteModal = false;
        $this->deletingSubscriberId = null;
        $this->savedMessage = 'PPPoE subscriber removed from RADIUS.';
    }

    public function renew(int $subscriberId, PppoeSubscriberManagementService $subscribers): void
    {
        $subscriber = PppoeSubscriber::with('package')->findOrFail($subscriberId);
        $subscribers->renew($subscriber, auth()->user());

        $this->savedMessage = 'PPPoE subscriber renewed and synced to RADIUS.';
    }

    public function inspect(int $subscriberId): void
    {
        $subscriber = PppoeSubscriber::with(['shop.tenant', 'package'])->findOrFail($subscriberId);
        TenantAccess::assertPppoeSubscriber($subscriber, auth()->user());

        $this->selectedSubscriberId = $subscriber->id;
        $this->showInspectModal = true;
    }

    public function closeInspect(): void
    {
        $this->showInspectModal = false;
        $this->selectedSubscriberId = null;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    public function render(PppoeSubscriberReportService $reports)
    {
        $this->validateOnlyFilters();

        $query = TenantAccess::scopePppoeSubscribers(PppoeSubscriber::with(['shop.tenant', 'package']), auth()->user())
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('username', 'like', "%{$this->search}%")
                        ->orWhere('full_name', 'like', "%{$this->search}%")
                        ->orWhere('phone', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->when($this->status === 'expired', fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->when($this->status === 'disabled', fn ($query) => $query->where('is_active', false));

        $subscribers = $query->latest()->paginate(15);
        $reports->attachUsage($subscribers->getCollection());

        return view('livewire.admin.pppoe-subscribers-index', [
            'subscribers' => $subscribers,
            'shops' => $this->shops(),
            'packages' => $this->packages(),
            'deletingSubscriber' => $this->deletingSubscriberId ? PppoeSubscriber::find($this->deletingSubscriberId) : null,
            'selectedSubscriber' => $this->selectedSubscriber($reports),
        ]);
    }

    private function selectedSubscriber(PppoeSubscriberReportService $reports): ?PppoeSubscriber
    {
        if (! $this->selectedSubscriberId) {
            return null;
        }

        $subscriber = PppoeSubscriber::with(['shop.tenant', 'package'])->find($this->selectedSubscriberId);

        if (! $subscriber) {
            return null;
        }

        TenantAccess::assertPppoeSubscriber($subscriber, auth()->user());
        $reports->attachUsage(collect([$subscriber]));
        $subscriber->setAttribute('radius_sessions', $reports->sessionsFor($subscriber));

        return $subscriber;
    }

    private function shops(): Collection
    {
        return TenantAccess::scopeShops(Shop::with('tenant'), auth()->user())->orderBy('name')->get();
    }

    private function packages(): Collection
    {
        $query = TenantAccess::scopePackages(Package::with('shop.tenant'), auth()->user())
            ->where('is_active', true)
            ->whereIn('service_type', ['pppoe', 'both'])
            ->orderBy('name');

        if ($this->shop_id) {
            $query->where('shop_id', $this->shop_id);
        }

        return $query->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingSubscriberId',
            'shop_id',
            'package_id',
            'username',
            'password',
            'full_name',
            'phone',
            'email',
            'starts_at',
            'expires_at',
            'is_active',
        ]);
        $this->is_active = true;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'status' => $this->status ?: null,
        ], [
            'status' => ['nullable', Rule::in(['active', 'expired', 'disabled'])],
        ])->validate();
    }
}
