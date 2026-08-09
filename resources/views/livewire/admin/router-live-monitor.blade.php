<div wire:poll.5s="poll">
    <div class="mb-4 flex flex-col justify-between gap-3 md:flex-row md:items-center">
        <div class="flex items-center gap-2">
            <label class="text-sm text-zinc-500 dark:text-zinc-400">Interface</label>
            @if ($interfaces !== [])
                <flux:select wire:model.live="interface" size="sm" class="w-56">
                    @foreach ($interfaces as $iface)
                        <flux:select.option value="{{ $iface['name'] }}">
                            {{ $iface['name'] }}{{ $iface['disabled'] ? ' (disabled)' : ($iface['running'] ? ' — up' : ' — down') }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:input wire:model.live="interface" size="sm" class="w-40" placeholder="wg-saas" />
            @endif
            <flux:button type="button" size="xs" variant="ghost" icon="arrow-path" wire:click="refreshInterfaces" wire:loading.attr="disabled" wire:target="refreshInterfaces" title="Refresh interface list" />
        </div>
        <flux:badge :color="$error ? 'red' : 'green'">{{ $error ? 'Unreachable' : 'Polling every 5s' }}</flux:badge>
    </div>

    @if ($interfacesError)
        <p class="mb-3 text-xs text-amber-600">Could not load the interface list ({{ $interfacesError }}) — type a name manually above.</p>
    @endif

    @if ($error)
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Could not reach this router over the RouterOS API: {{ $error }}
        </div>
    @elseif (count($samples) < 2)
        <p class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Waiting for the first live samples&hellip;</p>
    @else
        <flux:chart :value="$samples" class="aspect-[3/1]">
            <flux:chart.svg>
                <flux:chart.line field="download_mbps" class="text-blue-500" />
                <flux:chart.line field="upload_mbps" class="text-violet-500" />

                <flux:chart.axis axis="x" field="time">
                    <flux:chart.axis.tick />
                </flux:chart.axis>
                <flux:chart.axis axis="y">
                    <flux:chart.axis.grid />
                    <flux:chart.axis.tick />
                </flux:chart.axis>
            </flux:chart.svg>

            <flux:chart.tooltip>
                <flux:chart.tooltip.heading field="time" />
                <flux:chart.tooltip.value field="download_mbps" label="Download" suffix=" Mbps" />
                <flux:chart.tooltip.value field="upload_mbps" label="Upload" suffix=" Mbps" />
            </flux:chart.tooltip>
        </flux:chart>

        <div class="mt-3 flex justify-center gap-4">
            <flux:chart.legend label="Download">
                <flux:chart.legend.indicator class="bg-blue-500" />
            </flux:chart.legend>
            <flux:chart.legend label="Upload">
                <flux:chart.legend.indicator class="bg-violet-500" />
            </flux:chart.legend>
        </div>
    @endif

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Last {{ count($samples) }} of up to 30 samples (~2.5 minutes). Requires the RouterOS API credentials above to be applied on the physical router.</p>
</div>
