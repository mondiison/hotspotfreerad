<x-layouts.admin
    title="Topology"
    heading="Topology"
    subheading="Tenant, shop, and router hierarchy. Router status comes from FreeRADIUS accounting, refreshed on load."
>
    <div class="space-y-6">
        @forelse ($tenants as $row)
            <section class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-md bg-zinc-950 text-white">
                            <flux:icon.building-storefront class="size-4" />
                        </span>
                        <div>
                            <p class="font-semibold">{{ $row['tenant']->company_name }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $row['shops']->count() }} {{ \Illuminate\Support\Str::plural('shop', $row['shops']->count()) }} &middot; {{ $row['router_count'] }} {{ \Illuminate\Support\Str::plural('router', $row['router_count']) }}</p>
                        </div>
                    </div>
                    <flux:badge :color="$row['online_count'] === $row['router_count'] && $row['router_count'] > 0 ? 'green' : 'amber'">
                        {{ $row['online_count'] }}/{{ $row['router_count'] }} online
                    </flux:badge>
                </div>

                <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($row['shops'] as $shopGroup)
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.map-pin class="size-4 text-zinc-400 dark:text-zinc-500" />
                                <p class="font-medium">{{ $shopGroup['shop']->name }}</p>
                            </div>

                            <div class="mt-3 space-y-2">
                                @foreach ($shopGroup['routers'] as $router)
                                    <a
                                        href="{{ route('admin.routers.show', $router) }}"
                                        wire:navigate
                                        class="flex items-center justify-between gap-2 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-sm hover:border-zinc-400 dark:hover:border-zinc-500"
                                    >
                                        <span class="flex min-w-0 items-center gap-2">
                                            <span class="h-2 w-2 shrink-0 rounded-full {{ $router->detected_status === 'Online' ? 'bg-emerald-500' : ($router->detected_status === 'Recently seen' ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-zinc-600') }}"></span>
                                            <span class="min-w-0 truncate">{{ $router->name }}</span>
                                        </span>
                                        <span class="shrink-0 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $router->wireguard_internal_ip }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                No routers registered yet. Add a shop and router to see the topology here.
            </div>
        @endforelse
    </div>
</x-layouts.admin>
