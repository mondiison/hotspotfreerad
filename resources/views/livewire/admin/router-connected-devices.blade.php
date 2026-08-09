<div>
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-sm text-zinc-500 dark:text-zinc-400">Network</label>
                <flux:select wire:model.live="network" size="sm" class="w-40">
                    <flux:select.option value="mgmt">Management</flux:select.option>
                    <flux:select.option value="staff">Staff</flux:select.option>
                    <flux:select.option value="pos">POS</flux:select.option>
                    <flux:select.option value="hotspot">Hotspot</flux:select.option>
                </flux:select>
            </div>
        </div>
        <flux:button type="button" wire:click="refresh" variant="primary" size="sm" icon="arrow-path" wire:loading.attr="disabled" wire:target="refresh">
            <span wire:loading.remove wire:target="refresh">Refresh</span>
            <span wire:loading wire:target="refresh">Refreshing...</span>
        </flux:button>
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Live DHCP leases for this network -- works regardless of which access point (built-in Wi-Fi or an external AP) the client associated to, since the router hands out the lease either way.</p>

    @if ($statusMessage)
        <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $statusMessage }}
        </div>
    @endif

    @if ($error)
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Could not read DHCP leases: {{ $error }}
        </div>
    @elseif ($hasLoaded && empty($leases))
        <p class="mt-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No active leases on this network.</p>
    @elseif (! empty($leases))
        <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-[640px] w-full text-left text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">MAC Address</th>
                        <th class="px-4 py-2 font-medium">IP Address</th>
                        <th class="px-4 py-2 font-medium">Hostname</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Last seen</th>
                        @if (in_array($network, ['mgmt', 'staff'], true))
                            <th class="px-4 py-2 font-medium"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($leases as $lease)
                        <tr>
                            <td class="px-4 py-2 font-mono text-xs">{{ $lease['mac_address'] }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $lease['ip_address'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $lease['hostname'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $lease['status'] ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $lease['last_seen'] ?? '—' }}</td>
                            @if (in_array($network, ['mgmt', 'staff'], true))
                                <td class="px-4 py-2 text-right">
                                    <flux:button
                                        type="button"
                                        wire:click="registerAsTrusted(@js($lease['mac_address']), @js($lease['hostname'] ?? ''))"
                                        variant="outline"
                                        size="sm"
                                    >
                                        Register as trusted
                                    </flux:button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
