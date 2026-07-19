<x-layouts.admin title="Access" heading="Access" subheading="Current and expired customer internet access provisioned through hotspot packages.">
    <x-slot:action>
        <flux:button href="{{ route('admin.subscriptions.export', request()->query()) }}" variant="outline" icon="arrow-down-tray">Export CSV</flux:button>
    </x-slot:action>

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

    <form method="GET" class="mt-6 grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 lg:grid-cols-[1fr_160px_160px_160px_auto]">
        <flux:input name="search" value="{{ $filters['search'] }}" placeholder="Search MAC, shop, package, payment ref" />
        <flux:select name="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="active" :selected="$filters['status'] === 'active'">Active now</flux:select.option>
            <flux:select.option value="expired" :selected="$filters['status'] === 'expired'">Expired</flux:select.option>
        </flux:select>
        <flux:select name="source">
            <flux:select.option value="">All sources</flux:select.option>
            <flux:select.option value="paid" :selected="$filters['source'] === 'paid'">Paid access</flux:select.option>
            <flux:select.option value="test" :selected="$filters['source'] === 'test'">Test access</flux:select.option>
        </flux:select>
        <flux:select name="throttled">
            <flux:select.option value="">Any speed state</flux:select.option>
            <flux:select.option value="1" :selected="$filters['throttled'] === '1'">Throttled only</flux:select.option>
        </flux:select>
        <flux:button type="submit" variant="primary" icon="funnel">Filter</flux:button>
    </form>

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
                    @php
                        $isActive = $subscription->expires_at->isFuture();
                    @endphp
                    <tr>
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
                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">Paid</span>
                                <p class="mt-2 font-mono text-xs text-zinc-500">{{ $subscription->payment->tx_ref }}</p>
                            @else
                                <span class="rounded-full bg-sky-50 px-2 py-1 text-xs font-medium text-sky-700">Test</span>
                                <p class="mt-2 text-xs text-zinc-500">No payment attached</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-700' }}">
                                {{ $isActive ? 'Active' : 'Expired' }}
                            </span>
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
</x-layouts.admin>
