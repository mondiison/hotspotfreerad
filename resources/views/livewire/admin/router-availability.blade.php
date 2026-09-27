<div>
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-base font-semibold">Availability</h2>
        <div class="flex gap-2">
            <flux:button type="button" size="xs" :variant="$range === 'today' ? 'primary' : 'outline'" wire:click="setRange('today')">Today</flux:button>
            <flux:button type="button" size="xs" :variant="$range === '7d' ? 'primary' : 'outline'" wire:click="setRange('7d')">7 days</flux:button>
            <flux:button type="button" size="xs" :variant="$range === '30d' ? 'primary' : 'outline'" wire:click="setRange('30d')">30 days</flux:button>
        </div>
    </div>

    @if (! ($summary['has_samples'] ?? false))
        <p class="mt-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No heartbeat samples yet for this range. Samples are recorded every 5 minutes once the scheduler is running.</p>
    @else
        @php
            $uptimePercent = $summary['uptime_percent'];
            $uptimeColor = match (true) {
                $uptimePercent === null => 'text-zinc-500 dark:text-zinc-400',
                $uptimePercent >= 99.9 => 'text-emerald-600 dark:text-emerald-400',
                $uptimePercent >= 98.0 => 'text-amber-600 dark:text-amber-400',
                default => 'text-rose-600 dark:text-rose-400',
            };
            $humanize = fn (int $seconds): string => $seconds > 0
                ? \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true])
                : '0m';
            $totalRangeSeconds = max(1, $summary['range_start']->diffInSeconds($summary['range_end']));
            $cumulativeSeconds = 0;
        @endphp

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Uptime</p>
                <p class="mt-2 text-2xl font-semibold {{ $uptimeColor }}">{{ $uptimePercent !== null ? number_format($uptimePercent, 2).'%' : '—' }}</p>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Outages</p>
                <p class="mt-2 text-2xl font-semibold">{{ count($summary['downtime_incidents']) }}</p>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Total downtime</p>
                <p class="mt-2 text-2xl font-semibold">{{ $humanize($summary['downtime_seconds']) }}</p>
            </div>
        </div>

        <div class="relative mt-6 h-10 w-full overflow-visible rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800">
            @foreach ($summary['segments'] as $segment)
                @php
                    $leftPercent = $cumulativeSeconds / $totalRangeSeconds * 100;
                    $widthPercent = $segment['duration_seconds'] / $totalRangeSeconds * 100;
                    $cumulativeSeconds += $segment['duration_seconds'];

                    $colorClass = match ($segment['status']) {
                        'up' => 'bg-emerald-500 hover:bg-emerald-400',
                        'down' => 'bg-rose-500 hover:bg-rose-400',
                        default => 'bg-zinc-300 hover:bg-zinc-400 dark:bg-zinc-600 dark:hover:bg-zinc-500',
                    };
                    $statusLabel = match ($segment['status']) {
                        'up' => 'Online',
                        'down' => 'Offline',
                        default => 'No data',
                    };
                    $labelColor = match ($segment['status']) {
                        'up' => 'text-emerald-600 dark:text-emerald-400',
                        'down' => 'text-rose-600 dark:text-rose-400',
                        default => 'text-zinc-500 dark:text-zinc-400',
                    };
                @endphp
                <div
                    x-data="{ open: false }"
                    x-on:mouseenter="open = true"
                    x-on:mouseleave="open = false"
                    class="absolute inset-y-0 {{ $colorClass }} transition-colors"
                    style="left: {{ $leftPercent }}%; width: {{ $widthPercent }}%; min-width: 2px;"
                >
                    <div
                        x-show="open"
                        x-cloak
                        x-transition
                        class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 w-56 -translate-x-1/2 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-3 text-xs shadow-lg"
                    >
                        <p class="font-semibold {{ $labelColor }}">{{ $statusLabel }}</p>
                        <p class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $segment['start']->format('M j, H:i') }} &rarr; {{ $segment['end']->format('M j, H:i') }}</p>
                        <p class="mt-1 text-zinc-500 dark:text-zinc-500">Duration: {{ $humanize($segment['duration_seconds']) }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-2 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
            <span>{{ $summary['range_start']->format('M j, H:i') }}</span>
            <span>{{ $summary['range_end']->format('M j, H:i') }}</span>
        </div>

        <div class="mt-3 flex gap-4">
            <div class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                <span class="h-3 w-3 rounded-sm bg-emerald-500"></span> Online
            </div>
            <div class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                <span class="h-3 w-3 rounded-sm bg-rose-500"></span> Offline
            </div>
            <div class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                <span class="h-3 w-3 rounded-sm bg-zinc-300 dark:bg-zinc-600"></span> No data
            </div>
        </div>

        <div class="mt-8">
            <h3 class="text-sm font-semibold">Downtime incidents</h3>
            @if (count($summary['downtime_incidents']) === 0)
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No offline periods recorded in this range.</p>
            @else
                <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-[420px] w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-2 font-medium">Went offline</th>
                                <th class="px-4 py-2 font-medium">Back online</th>
                                <th class="px-4 py-2 font-medium">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach (array_reverse($summary['downtime_incidents']) as $incident)
                                <tr>
                                    <td class="px-4 py-2">{{ $incident['start']->format('M j, Y H:i') }}</td>
                                    <td class="px-4 py-2">{{ $incident['end']->format('M j, Y H:i') }}</td>
                                    <td class="px-4 py-2">{{ $humanize($incident['duration_seconds']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">Availability is derived from the same 5-minute heartbeat samples used elsewhere (ICMP ping over WireGuard/ZeroTier &mdash; no RouterOS API credentials needed). A gap longer than 15 minutes between samples is shown as "No data" rather than assumed offline.</p>
</div>
