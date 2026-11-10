<div x-data="{ copied: false }">
    <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Every unique phone number collected at hotspot checkout, from PPPoE subscribers, and from POS device owners &mdash; one row per person, even if they show up in more than one place. Build a WhatsApp broadcast/complaints group from the numbers below.</p>
        <div class="flex flex-wrap gap-2">
            <flux:button
                type="button"
                variant="outline"
                icon="clipboard"
                @click="copied = await window.copyText(@js($allNumbers)); setTimeout(() => copied = false, 1800)"
            >
                <span x-show="! copied">Copy all numbers</span>
                <span x-cloak x-show="copied">Copied</span>
            </flux:button>
            <flux:button type="button" variant="primary" icon="arrow-down-tray" href="{{ route('admin.customer-contacts.export', request()->only('search', 'shop')) }}" wire:navigate.hover>
                Export CSV
            </flux:button>
        </div>
    </div>

    @php
        $contactActiveFilters = [];
        if (filled($search)) {
            $contactActiveFilters[] = ['label' => 'Search: "'.$search.'"', 'clear' => "\$set('search', '')"];
        }
        if (filled($shopId)) {
            $contactActiveFilters[] = ['label' => 'Shop: '.($shops->firstWhere('id', (int) $shopId)?->name ?? $shopId), 'clear' => "\$set('shopId', '')"];
        }
    @endphp

    <x-admin.filter-bar modal-name="filters-customer-contacts" :active="$contactActiveFilters">
        <div class="sm:col-span-2">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search phone, name, shop" />
        </div>
        <flux:select wire:model.live="shopId">
            <flux:select.option value="">All shops</flux:select.option>
            @foreach ($shops as $shop)
                <flux:select.option value="{{ $shop->id }}">{{ $shop->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" class="w-full sm:col-span-2" wire:click="clearFilters" x-on:click="$flux.modal('filters-customer-contacts').close()" wire:loading.attr="disabled" wire:target="clearFilters,search,shopId">
            Reset
        </flux:button>
    </x-admin.filter-bar>

    <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">{{ number_format($totalUnique) }} unique phone number{{ $totalUnique === 1 ? '' : 's' }}.</p>

    <div wire:loading.flex wire:target="search,shopId,clearFilters" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating directory...
    </div>

    <div class="mt-4 overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <table class="min-w-[640px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Phone</th>
                    <th class="px-4 py-3 font-medium">Name</th>
                    <th class="px-4 py-3 font-medium">Seen as</th>
                    <th class="px-4 py-3 font-medium">Shop(s)</th>
                    <th class="px-4 py-3 text-right font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($contacts as $contact)
                    <tr>
                        <td class="px-4 py-3 font-mono font-medium">{{ $contact['phone'] }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $contact['contact_name'] ?: '-' }}</td>
                        <td class="px-4 py-3">
                            @foreach ($contact['sources'] as $source)
                                <flux:badge size="sm" color="{{ match ($source) { 'Hotspot' => 'emerald', 'PPPoE' => 'blue', default => 'purple' } }}">{{ $source }}</flux:badge>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">{{ implode(', ', $contact['shops']) ?: '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            <flux:button
                                type="button"
                                size="xs"
                                variant="outline"
                                icon="chat-bubble-left-right"
                                href="https://wa.me/234{{ substr($contact['phone'], 1) }}"
                                target="_blank"
                            >
                                WhatsApp
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                            No phone numbers collected yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $contacts->links() }}</div>
</div>
