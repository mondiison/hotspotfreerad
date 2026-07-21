<div>
    @php
        $formatBytes = function (?int $bytes): string {
            $bytes = (int) $bytes;

            if ($bytes <= 0) {
                return '0 B';
            }

            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
            $value = $bytes / (1024 ** $power);

            return number_format($value, $power === 0 ? 0 : 1).' '.$units[$power];
        };
    @endphp

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

    <div wire:loading.flex wire:target="inspect" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Loading access activity...
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
                    <th class="px-4 py-3 text-right font-medium">Transfer</th>
                    <th class="px-4 py-3 text-right font-medium">Access window</th>
                    <th class="px-4 py-3 text-right font-medium">Inspect</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($subscriptions as $subscription)
                    @php
                        $isActive = $subscription->expires_at->isFuture();
                        $usage = $subscription->radius_usage;
                    @endphp
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
                            @if ($usage['available'])
                                <p class="font-medium">{{ $formatBytes($usage['total_bytes']) }}</p>
                                <p class="mt-1 text-xs text-zinc-500">Down {{ $formatBytes($usage['download_bytes']) }}</p>
                                <p class="mt-1 text-xs text-zinc-500">Up {{ $formatBytes($usage['upload_bytes']) }}</p>
                                <p class="mt-1 text-xs text-zinc-400">{{ number_format($usage['session_count']) }} RADIUS {{ \Illuminate\Support\Str::plural('session', $usage['session_count']) }}</p>
                            @else
                                <p class="text-xs text-zinc-500">Accounting unavailable</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <p class="font-medium">{{ $subscription->expires_at->format('M j, Y g:i A') }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Started {{ $subscription->starts_at->format('M j, Y g:i A') }}</p>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <flux:button type="button" wire:click="inspect({{ $subscription->id }})" wire:loading.attr="disabled" wire:target="inspect({{ $subscription->id }})" variant="outline" size="sm" icon="magnifying-glass">
                                Deep inspect
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-zinc-500">No access records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $subscriptions->links() }}</div>

    <flux:modal wire:model.self="showInspectModal" class="md:w-5xl" :dismissible="true" variant="flyout">
        @if ($selectedSubscription)
            @php
                $usage = $selectedSubscription->radius_usage;
                $sessions = $selectedSubscription->radius_sessions;
            @endphp

            <div class="space-y-6">
                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                    <div>
                        <flux:heading level="2" size="lg">Access Activity</flux:heading>
                        <flux:text class="mt-2 text-sm text-zinc-500">
                            {{ $selectedSubscription->mac_address }} on {{ $selectedSubscription->shop?->name ?? 'Deleted shop' }}
                        </flux:text>
                    </div>

                    <flux:badge :color="$selectedSubscription->expires_at->isFuture() ? 'green' : 'zinc'">
                        {{ $selectedSubscription->expires_at->isFuture() ? 'Active' : 'Expired' }}
                    </flux:badge>
                </div>

                <section class="grid gap-3 md:grid-cols-4">
                    @foreach ([
                        ['label' => 'Total transfer', 'value' => $formatBytes($usage['total_bytes'])],
                        ['label' => 'Download', 'value' => $formatBytes($usage['download_bytes'])],
                        ['label' => 'Upload', 'value' => $formatBytes($usage['upload_bytes'])],
                        ['label' => 'RADIUS sessions', 'value' => number_format($usage['session_count'])],
                    ] as $stat)
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase text-zinc-500">{{ $stat['label'] }}</p>
                            <p class="mt-2 text-xl font-semibold">{{ $stat['value'] }}</p>
                        </div>
                    @endforeach
                </section>

                <section class="grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 text-sm md:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Package</p>
                        <p class="mt-1 font-medium">{{ $selectedSubscription->package?->name ?? 'Deleted package' }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ $selectedSubscription->package?->speed_limit_profile ?: 'No bandwidth profile' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Access window</p>
                        <p class="mt-1 font-medium">{{ $selectedSubscription->starts_at->format('M j, Y g:i A') }} - {{ $selectedSubscription->expires_at->format('M j, Y g:i A') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Source</p>
                        <p class="mt-1 font-medium">{{ $selectedSubscription->payment ? 'Paid' : 'Test' }}</p>
                        @if ($selectedSubscription->payment)
                            <p class="mt-1 font-mono text-xs text-zinc-500">{{ $selectedSubscription->payment->tx_ref }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Tenant</p>
                        <p class="mt-1 font-medium">{{ $selectedSubscription->shop?->tenant?->company_name ?? 'Unknown tenant' }}</p>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3">
                        <h3 class="font-semibold">RADIUS accounting sessions</h3>
                        <p class="mt-1 text-xs text-zinc-500">Only sessions whose start time falls inside this access window are included.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 text-zinc-500">
                                <tr>
                                    <th class="px-4 py-3 font-medium">Session</th>
                                    <th class="px-4 py-3 font-medium">Router / IP</th>
                                    <th class="px-4 py-3 font-medium">Started</th>
                                    <th class="px-4 py-3 font-medium">Updated / Stopped</th>
                                    <th class="px-4 py-3 text-right font-medium">Transfer</th>
                                    <th class="px-4 py-3 font-medium">End reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @forelse ($sessions as $session)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <p class="font-mono text-xs font-medium">{{ $session->acctsessionid }}</p>
                                            <p class="mt-1 font-mono text-xs text-zinc-500">{{ $session->acctuniqueid }}</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-mono text-xs">{{ $session->nasipaddress ?: 'Unknown NAS' }}</p>
                                            <p class="mt-1 font-mono text-xs text-zinc-500">{{ $session->framedipaddress ?: 'No framed IP' }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-zinc-600">
                                            {{ $session->acctstarttime ? \Illuminate\Support\Carbon::parse($session->acctstarttime)->format('M j, Y g:i A') : 'Unknown' }}
                                        </td>
                                        <td class="px-4 py-3 text-zinc-600">
                                            <p>{{ $session->acctupdatetime ? \Illuminate\Support\Carbon::parse($session->acctupdatetime)->format('M j, Y g:i A') : 'No update' }}</p>
                                            <p class="mt-1 text-xs text-zinc-500">{{ $session->acctstoptime ? 'Stopped '.\Illuminate\Support\Carbon::parse($session->acctstoptime)->format('M j, Y g:i A') : 'Still open / no stop' }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <p class="font-medium">{{ $formatBytes($session->total_bytes) }}</p>
                                            <p class="mt-1 text-xs text-zinc-500">Down {{ $formatBytes($session->download_bytes) }}</p>
                                            <p class="mt-1 text-xs text-zinc-500">Up {{ $formatBytes($session->upload_bytes) }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-zinc-600">
                                            {{ $session->acctterminatecause ?: 'Not stopped' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500">
                                            No RADIUS accounting sessions were found for this access window.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="flex justify-end">
                    <flux:button type="button" variant="outline" wire:click="closeInspect">Close</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
