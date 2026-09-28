@props([
    'modalName',
    'active' => [],
])

<div {{ $attributes->class('mb-4 flex flex-wrap items-center gap-2') }}>
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button type="button" variant="outline" size="sm" icon="funnel" tooltip="Show filters">
            Filters
            @if (count($active))
                <flux:badge size="sm" color="zinc">{{ count($active) }}</flux:badge>
            @endif
        </flux:button>
    </flux:modal.trigger>

    @foreach ($active as $chip)
        <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 py-1 ps-3 pe-1 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
            {{ $chip['label'] }}
            <flux:button
                type="button"
                size="xs"
                variant="ghost"
                square
                icon="x-mark"
                tooltip="Clear this filter"
                wire:click="{{ $chip['clear'] }}"
                wire:loading.attr="disabled"
                class="size-4! min-h-0! p-0!"
            />
        </span>
    @endforeach

    @if (count($active) > 1)
        <flux:button type="button" variant="ghost" size="sm" tooltip="Clear all filters" wire:click="clearFilters" wire:loading.attr="disabled">
            Clear all
        </flux:button>
    @endif
</div>

<flux:modal name="{{ $modalName }}" class="md:w-2xl">
    <div class="space-y-4" x-on:change="$flux.modal('{{ $modalName }}').close()">
        <flux:heading size="lg">Filters</flux:heading>

        <div class="grid min-w-0 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
            {{ $slot }}
        </div>
    </div>
</flux:modal>
