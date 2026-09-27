<x-layouts.admin
    title="Settings"
    heading="Settings"
    subheading="Shops, network, billing, team, and platform tools live here -- day-to-day work stays on the main menu."
>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach (\App\Support\NavGroups::visibleSettingsGroups(auth()->user()) as $group)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ $group['label'] }}</h2>

                <div class="mt-3 space-y-1">
                    @foreach ($group['links'] as $link)
                        <a
                            href="{{ route($link['route']) }}"
                            wire:navigate
                            class="flex items-center gap-3 rounded-md px-2 py-2 text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-800">
                                <x-dynamic-component :component="'flux::icon.'.$link['icon']" class="size-4" />
                            </span>
                            <span class="font-medium">{{ $link['label'] }}</span>
                            <flux:icon.chevron-right class="ml-auto size-4 shrink-0 text-zinc-300 dark:text-zinc-600" />
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-layouts.admin>
