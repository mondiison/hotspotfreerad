<div>
    <div class="mb-4 flex justify-end">
        <flux:button href="{{ route('admin.subscriptions.export', $exportQuery) }}" variant="outline" icon="arrow-down-tray">Export CSV</flux:button>
    </div>

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['label' => 'Access records', 'value' => number_format($summary['count']), 'hint' => 'Matching current filters'],
            ['label' => 'Active now', 'value' => number_format($summary['active_count']), 'hint' => 'Expiry time is still in the future'],
            ['label' => 'Expired', 'value' => number_format($summary['expired_count']), 'hint' => 'No longer valid by time limit'],
            ['label' => 'Paid access', 'value' => number_format($summary['paid_count']), 'hint' => 'Created from confirmed payment'],
            ['label' => 'Test access', 'value' => number_format($summary['test_count']), 'hint' => 'Manual or trial provisioning'],
            ['label' => 'Throttled', 'value' => number_format($summary['throttled_count']), 'hint' => 'FUP speed limit is active'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="mt-6 flex flex-wrap gap-2">
        @foreach ($presets as $key => $label)
            <flux:button
                type="button"
                wire:click="setPreset('{{ $key }}')"
                wire:loading.attr="disabled"
                wire:target="setPreset('{{ $key }}')"
                variant="{{ $filters['preset'] === $key ? 'primary' : 'outline' }}"
                size="sm"
            >
                {{ $label }}
            </flux:button>
        @endforeach

        <flux:button
            type="button"
            wire:click="showAllDates"
            variant="{{ $filters['preset'] || $filters['from'] ? 'outline' : 'primary' }}"
            size="sm"
        >
            All dates
        </flux:button>
    </section>

    <section class="mt-4 grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 lg:grid-cols-[140px_140px_1fr_150px_150px_150px_auto]">
        <flux:input type="date" wire:model.live="from" />
        <flux:input type="date" wire:model.live="to" />
        <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search MAC, shop, package, payment ref" />
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="active">Active now</flux:select.option>
            <flux:select.option value="expired">Expired</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="source">
            <flux:select.option value="">All sources</flux:select.option>
            <flux:select.option value="paid">Paid access</flux:select.option>
            <flux:select.option value="test">Test access</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="throttled">
            <flux:select.option value="">Any speed state</flux:select.option>
            <flux:select.option value="1">Throttled only</flux:select.option>
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,from,to,search,status,source,throttled">
            Reset
        </flux:button>
    </section>

    <div wire:loading.flex wire:target="from,to,search,status,source,throttled,setPreset,showAllDates,clearFilters" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating access report...
    </div>

    <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Device</th>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Source</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Access window</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($subscriptions as $subscription)
                    @php($isActive = $subscription->expires_at->isFuture())
                    <tr wire:key="subscription-{{ $subscription->id }}">
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-medium">{{ $subscription->mac_address }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Created {{ $subscription->created_at->diffForHumans() }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $subscription->package?->name ?? 'Deleted package' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $subscription->package?->speed_limit_profile ?: 'No bandwidth profile' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $subscription->shop?->name ?? 'Deleted shop' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $subscription->shop?->tenant?->company_name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            @if ($subscription->payment)
                                <flux:badge color="green">Paid</flux:badge>
                                <p class="mt-2 font-mono text-xs text-zinc-500">{{ $subscription->payment->tx_ref }}</p>
                            @else
                                <flux:badge color="sky">Test</flux:badge>
                                <p class="mt-2 text-xs text-zinc-500">No payment attached</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$isActive ? 'green' : 'zinc'">
                                {{ $isActive ? 'Active' : 'Expired' }}
                            </flux:badge>
                            @if ($subscription->is_throttled)
                                <p class="mt-2 text-xs font-medium text-amber-700">FUP throttled</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <p class="font-medium">{{ $subscription->expires_at->format('M j, Y g:i A') }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Started {{ $subscription->starts_at->format('M j, Y g:i A') }}</p>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No access records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $subscriptions->links() }}</div>
</div>
