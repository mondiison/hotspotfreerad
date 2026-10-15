<div>
    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
        <div>
            <h2 class="text-base font-semibold">Port Map</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">What's configured on each physical port, based on this router's saved settings -- not live link status. Hover a port for detail.</p>
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
            @foreach ($ports as $port)
                <div
                    x-data="{ hovering: false }"
                    class="relative"
                    @mouseenter="hovering = true"
                    @mouseleave="hovering = false"
                >
                    <flux:badge color="{{ $port['role_color'] }}" size="lg" class="cursor-default select-none">
                        Port {{ $port['number'] }}
                    </flux:badge>

                    <div
                        x-show="hovering"
                        x-cloak
                        x-transition.opacity.duration.150ms
                        class="absolute z-10 mt-2 w-64 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-3 text-xs shadow-lg"
                    >
                        <p class="font-semibold text-zinc-950 dark:text-zinc-100">{{ $port['interface'] }} &mdash; {{ $port['role_label'] }}</p>
                        <p class="mt-1 font-medium text-zinc-500 dark:text-zinc-400">{{ $port['vlan_label'] }}</p>
                        <p class="mt-2 leading-5 text-zinc-600 dark:text-zinc-400">{{ $port['detail'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-zinc-200 dark:border-zinc-700 pt-4">
            @foreach ($legend as $item)
                <flux:badge color="{{ $item['color'] }}" size="sm">{{ $item['label'] }}</flux:badge>
            @endforeach
        </div>
    </section>
</div>
