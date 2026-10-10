<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\SortsTable;
use App\Models\Subscription;
use App\Services\RadiusProvisioningService;
use App\Services\SubscriptionReportService;
use App\Support\TenantAccess;
use Livewire\Component;
use Livewire\WithPagination;

class SubscriptionsIndex extends Component
{
    use SortsTable, WithPagination;

    public string $preset = '';

    public string $from = '';

    public string $to = '';

    public string $search = '';

    public string $status = '';

    public string $source = '';

    public string $throttled = '';

    public bool $showInspectModal = false;

    public ?int $selectedSubscriptionId = null;

    public bool $editingMacAddress = false;

    public string $newMacAddress = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    protected $queryString = [
        'preset' => ['except' => ''],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'source' => ['except' => ''],
        'throttled' => ['except' => ''],
        'sortBy' => ['except' => 'created_at'],
        'sortDirection' => ['except' => 'desc'],
    ];

    public function mount(array $filters = []): void
    {
        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? '');
        $this->to = (string) ($filters['to'] ?? '');
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->source = (string) ($filters['source'] ?? '');
        $this->throttled = (string) ($filters['throttled'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = '';
        }

        if (in_array($property, ['preset', 'from', 'to', 'search', 'status', 'source', 'throttled'], true)) {
            $this->resetPage();
        }
    }

    public function setPreset(string $preset, SubscriptionReportService $reports): void
    {
        if (! array_key_exists($preset, $reports->presets())) {
            return;
        }

        $filters = $reports->filters([
            'preset' => $preset,
            'status' => $this->status,
            'source' => $this->source,
            'throttled' => $this->throttled,
            'search' => $this->search,
        ]);

        $this->preset = (string) $filters['preset'];
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];
        $this->resetPage();
    }

    public function showAllDates(): void
    {
        $this->preset = '';
        $this->from = '';
        $this->to = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['preset', 'from', 'to', 'search', 'status', 'source', 'throttled']);
        $this->resetPage();
    }

    /**
     * 2026-10-10, direct request to make summary stat tiles clickable --
     * mirrors PackagesIndex::filterBy()'s exact shape (replace the relevant
     * filters wholesale rather than toggle, so clicking a different tile
     * never leaves a stale filter combination from a previous click).
     */
    public function filterBySummary(string $status = '', string $source = '', string $throttled = ''): void
    {
        $this->status = $status;
        $this->source = $source;
        $this->throttled = $throttled;
        $this->resetPage();
    }

    /**
     * 2026-10-09: the only two date columns on this table (expires_at
     * behind "Access window", created_at behind the "Created ..."
     * sub-line on "Device") -- exhaustive by design, not a placeholder.
     */
    protected function sortableColumns(): array
    {
        return ['created_at', 'expires_at'];
    }

    public function inspect(int $subscriptionId): void
    {
        $subscription = Subscription::with(['shop.tenant', 'package', 'payment'])->findOrFail($subscriptionId);
        TenantAccess::assertSubscription($subscription, auth()->user());

        $this->selectedSubscriptionId = $subscription->id;
        $this->editingMacAddress = false;
        $this->newMacAddress = '';
        $this->resetErrorBag('newMacAddress');
        $this->showInspectModal = true;
    }

    public function closeInspect(): void
    {
        $this->showInspectModal = false;
        $this->selectedSubscriptionId = null;
        $this->editingMacAddress = false;
        $this->newMacAddress = '';
    }

    public function startEditingMacAddress(): void
    {
        $this->newMacAddress = '';
        $this->resetErrorBag('newMacAddress');
        $this->editingMacAddress = true;
    }

    /**
     * 2026-10-09, direct request: a customer's phone presenting a new
     * (privacy-randomized) MAC on reconnect has no active subscription
     * under that MAC and gets sent back to the payment page for access
     * they already paid for. Lets staff retarget the subscription at the
     * device's new MAC instead.
     */
    public function changeMacAddress(RadiusProvisioningService $radius): void
    {
        $subscription = Subscription::findOrFail($this->selectedSubscriptionId);
        TenantAccess::assertSubscription($subscription, auth()->user());

        $this->validate(['newMacAddress' => ['required', 'string', 'max:64']]);

        $normalized = $radius->normalizeMacAddress($this->newMacAddress);

        if (strlen(str_replace(':', '', $normalized)) !== 12) {
            $this->addError('newMacAddress', 'Enter a valid MAC address (6 pairs of hex digits, e.g. AA:BB:CC:DD:EE:FF).');

            return;
        }

        $collision = Subscription::query()
            ->where('shop_id', $subscription->shop_id)
            ->where('mac_address', $normalized)
            ->where('id', '!=', $subscription->id)
            ->where('expires_at', '>', now())
            ->exists();

        if ($collision) {
            $this->addError('newMacAddress', 'Another active subscription at this shop already uses that MAC address.');

            return;
        }

        $radius->changeSubscriptionMacAddress($subscription, $normalized);

        $this->editingMacAddress = false;
        $this->newMacAddress = '';
        $this->dispatch('notify', message: 'Device MAC address updated -- access has moved to the new device.');
    }

    public function render(SubscriptionReportService $reports)
    {
        $filters = $reports->filters([
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'status' => $this->status,
            'source' => $this->source,
            'throttled' => $this->throttled,
        ]);

        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? '');
        $this->to = (string) ($filters['to'] ?? '');

        $query = $reports->query(auth()->user(), $filters);

        $subscriptions = $query->orderBy($this->sortBy, $this->sortDirection)->paginate(20);
        $reports->attachUsage($subscriptions->getCollection());

        return view('livewire.admin.subscriptions-index', [
            'subscriptions' => $subscriptions,
            'selectedSubscription' => $this->selectedSubscription($reports),
            'summary' => $reports->summary(clone $query),
            'filters' => $filters,
            'presets' => $reports->presets(),
            'exportQuery' => $reports->queryParams($filters),
        ]);
    }

    private function selectedSubscription(SubscriptionReportService $reports): ?Subscription
    {
        if (! $this->selectedSubscriptionId) {
            return null;
        }

        $subscription = Subscription::with(['shop.tenant', 'package', 'payment'])->find($this->selectedSubscriptionId);

        if (! $subscription) {
            return null;
        }

        TenantAccess::assertSubscription($subscription, auth()->user());
        $reports->attachUsage(collect([$subscription]));
        $subscription->setAttribute('radius_sessions', $reports->sessionsFor($subscription));

        return $subscription;
    }
}
