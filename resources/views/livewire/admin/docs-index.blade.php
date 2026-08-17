<div class="flex flex-col gap-4 lg:flex-row lg:items-start">
    <div class="{{ $showListOnMobile ? 'block' : 'hidden' }} w-full shrink-0 lg:block lg:w-80">
        <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search docs..." />

        <nav class="mt-3 max-h-[32rem] space-y-1 overflow-y-auto rounded-lg border border-zinc-200 bg-white p-2 lg:max-h-[70vh] dark:border-zinc-700 dark:bg-zinc-900">
            @forelse ($this->filteredEntries as $entry)
                <button
                    type="button"
                    wire:click="open('{{ $entry['slug'] }}')"
                    wire:key="doc-{{ $entry['slug'] }}"
                    class="block w-full rounded-md px-3 py-2 text-left {{ $activeSlug === $entry['slug'] ? 'bg-zinc-950 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}"
                >
                    <span class="block truncate text-sm font-medium">{{ $entry['title'] }}</span>
                    @if ($entry['excerpt'] !== '')
                        <span class="mt-0.5 line-clamp-2 block text-xs {{ $activeSlug === $entry['slug'] ? 'text-white/70 dark:text-zinc-900/70' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $entry['excerpt'] }}</span>
                    @endif
                </button>
            @empty
                <p class="p-3 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($search !== '')
                        No docs match &ldquo;{{ $search }}&rdquo;.
                    @else
                        No docs found in the docs/ folder.
                    @endif
                </p>
            @endforelse
        </nav>
    </div>

    <div class="{{ $showListOnMobile ? 'hidden' : 'block' }} min-w-0 flex-1 rounded-lg border border-zinc-200 bg-white p-5 lg:block lg:p-8 dark:border-zinc-700 dark:bg-zinc-900">
        <button
            type="button"
            wire:click="back"
            class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-zinc-600 hover:text-zinc-900 lg:hidden dark:text-zinc-400 dark:hover:text-zinc-100"
        >
            <flux:icon.chevron-left class="size-4" />
            Back to docs
        </button>

        @if ($this->activeEntry)
            <article class="prose prose-zinc max-w-none dark:prose-invert">
                {!! $this->activeHtml !!}
            </article>
        @else
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Select a document from the list to view it.</p>
        @endif
    </div>
</div>
