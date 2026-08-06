<div>
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0 flex-1">
            <label class="text-sm text-zinc-500">Command</label>
            <flux:input wire:model="command" wire:keydown.enter="run" size="sm" class="font-mono" placeholder="/interface print" />
        </div>
        <flux:button type="button" wire:click="run" variant="primary" size="sm" icon="play" wire:loading.attr="disabled" wire:target="run">
            <span wire:loading.remove wire:target="run">Run</span>
            <span wire:loading wire:target="run">Running...</span>
        </flux:button>
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
        @foreach ([
            '/system resource print',
            '/interface print',
            '/ip hotspot active print',
            '/ppp active print',
            '/ip address print',
        ] as $example)
            <button type="button" wire:click="$set('command', '{{ $example }}')" class="rounded-full border border-zinc-200 px-3 py-1 font-mono text-xs text-zinc-600 hover:bg-zinc-50">{{ $example }}</button>
        @endforeach
    </div>

    <p class="mt-3 text-xs text-zinc-500">Read-only console: only <code>print</code> commands are accepted, and the underlying API user has no write access on the router either way. Add filters with <code>where field=value</code>.</p>

    @if ($error)
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $error }}
        </div>
    @elseif ($hasRun && empty($rows))
        <p class="mt-4 py-6 text-center text-sm text-zinc-500">No rows returned for <code class="font-mono">{{ $path }}</code>.</p>
    @elseif (! empty($rows))
        <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200">
            <table class="w-full min-w-max text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                    <tr>
                        @foreach ($columns as $column)
                            <th class="whitespace-nowrap px-4 py-2 font-medium">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($columns as $column)
                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ $row[$column] ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
