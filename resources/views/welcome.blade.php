<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'HotspotFreeRAD') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
        <div class="min-h-screen">
            <aside class="fixed inset-y-0 left-0 hidden w-64 border-r border-white/10 bg-zinc-950/95 px-5 py-6 lg:block">
                <div class="flex items-center gap-3">
                    <div class="grid size-9 place-items-center rounded-lg bg-emerald-400 text-sm font-bold text-zinc-950">HF</div>
                    <div>
                        <p class="text-sm font-semibold">HotspotFreeRAD</p>
                        <p class="text-xs text-zinc-400">Tenant operations</p>
                    </div>
                </div>

                <nav class="mt-8 space-y-1 text-sm">
                    <a class="flex items-center gap-3 rounded-lg bg-white/10 px-3 py-2 font-medium text-white" href="#">
                        <flux:icon.squares-2x2 class="size-4" />
                        Overview
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-zinc-400 hover:bg-white/5 hover:text-white" href="#">
                        <flux:icon.building-storefront class="size-4" />
                        Shops
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-zinc-400 hover:bg-white/5 hover:text-white" href="#">
                        <flux:icon.signal class="size-4" />
                        Routers
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-zinc-400 hover:bg-white/5 hover:text-white" href="#">
                        <flux:icon.credit-card class="size-4" />
                        Payments
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-zinc-400 hover:bg-white/5 hover:text-white" href="#">
                        <flux:icon.radio class="size-4" />
                        RADIUS
                    </a>
                </nav>
            </aside>

            <main class="lg:pl-64">
                <header class="border-b border-white/10 bg-zinc-950/80 px-5 py-4 backdrop-blur sm:px-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-emerald-300">Local SaaS build</p>
                            <h1 class="mt-1 text-2xl font-semibold tracking-normal text-white">Hotspot operations dashboard</h1>
                        </div>

                        <div class="flex gap-2">
                            <flux:button variant="ghost" icon="book-open">Blueprint</flux:button>
                            <flux:button variant="primary" icon="plus">Add tenant</flux:button>
                        </div>
                    </div>
                </header>

                <section class="px-5 py-6 sm:px-8">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
                            <p class="text-sm text-zinc-400">Tenants</p>
                            <p class="mt-3 text-3xl font-semibold text-white">0</p>
                            <p class="mt-2 text-xs text-zinc-500">Ready for onboarding flow</p>
                        </div>

                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
                            <p class="text-sm text-zinc-400">Routers online</p>
                            <p class="mt-3 text-3xl font-semibold text-white">0</p>
                            <p class="mt-2 text-xs text-zinc-500">WireGuard and RADIUS pending</p>
                        </div>

                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
                            <p class="text-sm text-zinc-400">Active sessions</p>
                            <p class="mt-3 text-3xl font-semibold text-white">0</p>
                            <p class="mt-2 text-xs text-zinc-500">FreeRADIUS accounting not connected</p>
                        </div>

                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
                            <p class="text-sm text-zinc-400">Today revenue</p>
                            <p class="mt-3 text-3xl font-semibold text-white">NGN 0</p>
                            <p class="mt-2 text-xs text-zinc-500">Flutterwave flow coming next</p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <div class="rounded-lg border border-white/10 bg-zinc-900/60">
                            <div class="border-b border-white/10 px-5 py-4">
                                <h2 class="text-base font-semibold text-white">Build checklist</h2>
                            </div>

                            <div class="divide-y divide-white/10">
                                @foreach ([
                                    ['done' => true, 'label' => 'Laravel app scaffolded', 'detail' => 'Laravel 12 installed for local PHP 8.2 compatibility.'],
                                    ['done' => true, 'label' => 'Livewire and Flux installed', 'detail' => 'Flux Pro can be activated with your license key.'],
                                    ['done' => true, 'label' => 'Core SaaS schema drafted', 'detail' => 'Tenants, shops, routers, packages, customers, payments, and subscriptions.'],
                                    ['done' => false, 'label' => 'Authentication and tenant dashboard', 'detail' => 'Next slice: login, tenant shell, and shop setup forms.'],
                                    ['done' => false, 'label' => 'Captive portal payment flow', 'detail' => 'Flutterwave checkout and webhook provisioning.'],
                                ] as $item)
                                    <div class="flex gap-4 px-5 py-4">
                                        <div class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full {{ $item['done'] ? 'bg-emerald-400 text-zinc-950' : 'bg-white/10 text-zinc-500' }}">
                                            <flux:icon.check class="size-4" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-white">{{ $item['label'] }}</p>
                                            <p class="mt-1 text-sm text-zinc-400">{{ $item['detail'] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                            <h2 class="text-base font-semibold text-white">Next operator flow</h2>
                            <div class="mt-4 space-y-4">
                                <div class="rounded-lg bg-white/[0.04] p-4">
                                    <p class="text-sm font-medium text-white">1. Create tenant</p>
                                    <p class="mt-1 text-sm text-zinc-400">Register the ISP owner and subscription plan.</p>
                                </div>
                                <div class="rounded-lg bg-white/[0.04] p-4">
                                    <p class="text-sm font-medium text-white">2. Add shop and router</p>
                                    <p class="mt-1 text-sm text-zinc-400">Generate MikroTik setup from NAS ID, shared secret, and WireGuard IP.</p>
                                </div>
                                <div class="rounded-lg bg-white/[0.04] p-4">
                                    <p class="text-sm font-medium text-white">3. Publish packages</p>
                                    <p class="mt-1 text-sm text-zinc-400">Define price, session time, rate limit, and FUP thresholds.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </div>

        @fluxScripts
    </body>
</html>
