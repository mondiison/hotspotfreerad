<div>
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <p class="text-xs text-zinc-500 dark:text-zinc-400">List this router's real directories first -- the default (<code>{{ $defaultDirectory }}</code>) is only correct if this router's hotspot server was set up by this app. A router with an existing hotspot setup can use a different directory (no <code>flash/</code> prefix on some RouterOS versions). A successful push below is saved as this router's directory, so "Provision via API" and the auto-provisioning sync keep using it too, instead of reverting to the default every time.</p>
        <flux:button type="button" wire:click="listDirectories" variant="outline" size="sm" icon="folder-open" wire:loading.attr="disabled" wire:target="listDirectories" class="shrink-0">
            <span wire:loading.remove wire:target="listDirectories">List directories</span>
            <span wire:loading wire:target="listDirectories">Listing...</span>
        </flux:button>
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
        @if ($savedDirectory)
            Saved directory for this router: <code>{{ $savedDirectory }}</code>.
            <button type="button" wire:click="resetToDefault" class="font-medium text-blue-600 hover:underline">Reset to default</button>
        @else
            No directory saved for this router yet -- using the default (<code>{{ $defaultDirectory }}</code>).
        @endif
    </p>

    @if ($listError)
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Could not list directories: {{ $listError }}
        </div>
    @elseif ($hasListed && empty($directories))
        <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">No directories found on this router.</p>
    @endif

    <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-end">
        <div class="flex-1">
            <label class="text-sm text-zinc-500 dark:text-zinc-400">Directory</label>

            @if (! $useCustomDirectory)
                @if (! empty($directories))
                    <flux:select wire:model="selectedDirectory" size="sm">
                        @foreach ($directories as $directory)
                            <flux:select.option :value="$directory">{{ $directory }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:input wire:model="selectedDirectory" size="sm" placeholder="flash/hotspot" />
                @endif
            @else
                <flux:input wire:model="customDirectory" size="sm" placeholder="e.g. hotspot1" />
            @endif
        </div>

        <label class="flex shrink-0 items-center gap-2 pb-1.5 text-sm text-zinc-600 dark:text-zinc-300">
            <flux:checkbox wire:model.live="useCustomDirectory" />
            Type manually
        </label>

        <flux:button type="button" wire:click="push" variant="primary" size="sm" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="push" class="shrink-0">
            <span wire:loading.remove wire:target="push">Push login page</span>
            <span wire:loading wire:target="push">Pushing...</span>
        </flux:button>
    </div>

    @if ($statusMessage)
        <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $statusMessage }}
        </div>
    @endif

    @if ($pushError)
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Push failed: {{ $pushError }}
        </div>
    @endif
</div>
