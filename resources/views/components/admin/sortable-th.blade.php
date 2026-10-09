@props(['column', 'sortBy', 'sortDirection', 'align' => 'left'])

<th {{ $attributes->merge(['class' => 'px-4 py-3 font-medium'.($align === 'right' ? ' text-right' : '')]) }}>
    <button
        type="button"
        wire:click="sortByColumn('{{ $column }}')"
        class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100 {{ $align === 'right' ? 'w-full justify-end' : '' }}"
    >
        {{ $slot }}
        @if ($sortBy === $column)
            <flux:icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="size-3" />
        @else
            <flux:icon name="chevron-up-down" class="size-3 text-zinc-300 dark:text-zinc-600" />
        @endif
    </button>
</th>
