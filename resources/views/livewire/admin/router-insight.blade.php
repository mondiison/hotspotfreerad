<div>
    <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-base font-semibold">Live Snapshot</h2>
        <flux:button type="button" wire:click="refreshLive" variant="outline" size="sm" icon="arrow-path" wire:loading.attr="disabled" wire:target="refreshLive">
            <span wire:loading.remove wire:target="refreshLive">Refresh</span>
            <span wire:loading wire:target="refreshLive">Refreshing...</span>
        </flux:button>
    </div>

    @if ($liveError)
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Could not reach this router over the RouterOS API: {{ $liveError }}
        </div>
    @elseif ($liveResource)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">CPU</p>
                <p class="mt-2 text-2xl font-semibold">{{ $liveResource['cpu_percent'] }}%</p>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">RAM</p>
                <p class="mt-2 text-2xl font-semibold">{{ number_format($liveResource['ram_used_bytes'] / 1048576, 0) }} / {{ number_format($liveResource['ram_total_bytes'] / 1048576, 0) }} MB</p>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Disk</p>
                <p class="mt-2 text-2xl font-semibold">{{ number_format($liveResource['disk_used_bytes'] / 1048576, 0) }} / {{ number_format($liveResource['disk_total_bytes'] / 1048576, 0) }} MB</p>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Uptime</p>
                <p class="mt-2 text-2xl font-semibold">{{ \Carbon\CarbonInterval::seconds($liveResource['uptime_seconds'])->cascade()->forHumans(['short' => true]) }}</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $liveResource['board_name'] }} &middot; RouterOS {{ $liveResource['version'] }}</p>

        @if (! empty($health))
            <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-[320px] w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 font-medium">Sensor</th>
                            <th class="px-4 py-2 font-medium">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($health as $key => $value)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $key }}</td>
                                <td class="px-4 py-2">{{ is_scalar($value) ? $value : json_encode($value) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">No hardware health sensors reported by this router (common on hardware without fans/temperature sensors).</p>
        @endif
    @endif

    <div class="mt-8 flex items-center justify-between gap-3">
        <h2 class="text-base font-semibold">History</h2>
        <div class="flex gap-2">
            <flux:button type="button" size="xs" :variant="$range === 'today' ? 'primary' : 'outline'" wire:click="setRange('today')">Today</flux:button>
            <flux:button type="button" size="xs" :variant="$range === '7d' ? 'primary' : 'outline'" wire:click="setRange('7d')">7 days</flux:button>
            <flux:button type="button" size="xs" :variant="$range === '30d' ? 'primary' : 'outline'" wire:click="setRange('30d')">30 days</flux:button>
        </div>
    </div>

    @if (count($series) < 2)
        <p class="mt-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Not enough samples yet for this range. Samples are recorded every 5 minutes once the scheduler is running.</p>
    @else
        <div class="mt-4 grid gap-6 xl:grid-cols-2">
            <div>
                <p class="mb-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">CPU / RAM</p>
                <flux:chart :value="$series" class="aspect-[2/1]">
                    <flux:chart.svg>
                        <flux:chart.line field="cpu_percent" class="text-blue-500" />
                        <flux:chart.line field="ram_percent" class="text-violet-500" />
                        <flux:chart.axis axis="x" field="label"><flux:chart.axis.tick /></flux:chart.axis>
                        <flux:chart.axis axis="y"><flux:chart.axis.grid /><flux:chart.axis.tick /></flux:chart.axis>
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="label" />
                        <flux:chart.tooltip.value field="cpu_percent" label="CPU" suffix="%" />
                        <flux:chart.tooltip.value field="ram_percent" label="RAM" suffix="%" />
                    </flux:chart.tooltip>
                </flux:chart>
                <div class="mt-2 flex justify-center gap-4">
                    <flux:chart.legend label="CPU"><flux:chart.legend.indicator class="bg-blue-500" /></flux:chart.legend>
                    <flux:chart.legend label="RAM"><flux:chart.legend.indicator class="bg-violet-500" /></flux:chart.legend>
                </div>
            </div>

            <div>
                <p class="mb-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">Latency to router (ms)</p>
                <flux:chart :value="$series" class="aspect-[2/1]">
                    <flux:chart.svg>
                        <flux:chart.line field="latency_ms" class="text-emerald-500" />
                        <flux:chart.axis axis="x" field="label"><flux:chart.axis.tick /></flux:chart.axis>
                        <flux:chart.axis axis="y"><flux:chart.axis.grid /><flux:chart.axis.tick /></flux:chart.axis>
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="label" />
                        <flux:chart.tooltip.value field="latency_ms" label="Latency" suffix=" ms" />
                    </flux:chart.tooltip>
                </flux:chart>
                <div class="mt-2 flex justify-center">
                    <flux:chart.legend label="Latency"><flux:chart.legend.indicator class="bg-emerald-500" /></flux:chart.legend>
                </div>
            </div>
        </div>
    @endif

    <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">Latency is measured by pinging this router's WireGuard IP directly &mdash; it doesn't need RouterOS API credentials. CPU/RAM/disk/health need the API section of the script applied on the router.</p>
</div>
