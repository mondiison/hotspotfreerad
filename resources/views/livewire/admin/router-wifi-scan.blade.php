<div>
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-sm text-zinc-500 dark:text-zinc-400">Interface</label>
                <flux:input wire:model.blur="interface" size="sm" class="w-40" placeholder="wifi1" />
            </div>
            <flux:checkbox wire:model="legacyWireless" label="Legacy wireless package" />
        </div>
        <flux:button type="button" wire:click="scan" variant="primary" size="sm" icon="magnifying-glass" wire:loading.attr="disabled" wire:target="scan">
            <span wire:loading.remove wire:target="scan">Scan now</span>
            <span wire:loading wire:target="scan">Scanning...</span>
        </flux:button>
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Scanning briefly interrupts client connections on some wireless drivers when run on an interface that's actively serving an SSID. Use the built-in Wi-Fi interface name from the router's script (for example <code>wifi1</code> on the L009 test profile).</p>

    @if ($error)
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Scan failed: {{ $error }}
        </div>
    @elseif ($hasScanned && empty($networks))
        <p class="mt-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No networks found in range.</p>
    @elseif (! empty($networks))
        <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-[480px] w-full text-left text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">SSID</th>
                        <th class="px-4 py-2 font-medium">Frequency</th>
                        <th class="px-4 py-2 font-medium">Signal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($networks as $network)
                        <tr>
                            <td class="px-4 py-2">{{ $network['ssid'] }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $network['frequency'] ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $network['signal'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
