<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tenant->company_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-zinc-950 antialiased" style="--brand: {{ $tenant->brand_color ?? '#0f766e' }}">
    <main>
        <section class="border-b border-zinc-200 bg-white">
            <div class="mx-auto grid min-h-[82vh] max-w-6xl content-center gap-10 px-5 py-10 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
                <div>
                    <p class="text-sm font-medium uppercase tracking-wide" style="color: var(--brand)">{{ $tenant->subscription_plan }} hotspot network</p>
                    <h1 class="mt-4 max-w-3xl text-4xl font-semibold leading-tight md:text-6xl">{{ $tenant->company_name }}</h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-zinc-600">
                        {{ $tenant->public_site_tagline ?: 'Simple, reliable internet access for customers across active hotspot locations.' }}
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="#plans" class="rounded-md px-5 py-3 text-sm font-semibold text-white" style="background-color: var(--brand)">View internet plans</a>
                        @if ($tenant->contact_phone)
                            <a href="tel:{{ $tenant->contact_phone }}" class="rounded-md border border-zinc-300 bg-white px-5 py-3 text-sm font-semibold">Call support</a>
                        @endif
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-950 p-6 text-white shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm text-zinc-400">Network locations</p>
                            <p class="mt-2 text-4xl font-semibold">{{ $tenant->shops->count() }}</p>
                        </div>
                        <div class="rounded-md px-3 py-2 text-sm" style="background-color: var(--brand)">Live</div>
                    </div>

                    <div class="mt-8 space-y-4">
                        @forelse ($tenant->shops->take(3) as $shop)
                            <div class="border-t border-white/10 pt-4">
                                <p class="font-medium">{{ $shop->name }}</p>
                                <p class="mt-1 text-sm text-zinc-400">{{ $shop->packages->count() }} active {{ \Illuminate\Support\Str::plural('plan', $shop->packages->count()) }}{{ $shop->location_city ? ' in '.$shop->location_city : '' }}</p>
                            </div>
                        @empty
                            <div class="border-t border-white/10 pt-4 text-sm text-zinc-400">No public hotspot locations have been added yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section id="plans" class="mx-auto max-w-6xl px-5 py-12">
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                <div>
                    <h2 class="text-2xl font-semibold">Available Internet Access</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600">Plans are shown by location. When customers connect to the Wi-Fi, the MikroTik captive portal sends them to the matching login page automatically.</p>
                </div>
            </div>

            <div class="mt-6 space-y-6">
                @forelse ($tenant->shops as $shop)
                    <section class="rounded-lg border border-zinc-200 bg-white p-5">
                        <div class="flex flex-col justify-between gap-2 md:flex-row md:items-center">
                            <div>
                                <h3 class="text-lg font-semibold">{{ $shop->name }}</h3>
                                @if ($shop->location_city)
                                    <p class="mt-1 text-sm text-zinc-500">{{ $shop->location_city }}</p>
                                @endif
                            </div>
                            <span class="text-sm text-zinc-500">{{ $shop->packages->count() }} active {{ \Illuminate\Support\Str::plural('plan', $shop->packages->count()) }}</span>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-3">
                            @forelse ($shop->packages as $package)
                                <article class="rounded-lg border border-zinc-200 p-4">
                                    <h4 class="font-semibold">{{ $package->name }}</h4>
                                    <p class="mt-3 text-2xl font-semibold">{{ $package->currency }} {{ number_format($package->price, 2) }}</p>
                                    <dl class="mt-4 space-y-2 text-sm text-zinc-600">
                                        <div class="flex justify-between gap-4">
                                            <dt>Speed</dt>
                                            <dd class="font-medium text-zinc-950">{{ $package->speed_limit_profile }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-4">
                                            <dt>Uptime</dt>
                                            <dd class="font-medium text-zinc-950">{{ $package->limit_uptime_seconds >= 86400 ? number_format($package->limit_uptime_seconds / 86400) . ' days' : gmdate('H:i', $package->limit_uptime_seconds) }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-4">
                                            <dt>Data</dt>
                                            <dd class="font-medium text-zinc-950">{{ $package->data_limit_bytes ? number_format($package->data_limit_bytes / 1073741824, 1) . ' GB' : 'Unlimited' }}</dd>
                                        </div>
                                    </dl>
                                </article>
                            @empty
                                <div class="rounded-lg border border-dashed border-zinc-300 p-4 text-sm text-zinc-500 md:col-span-3">No active plans are published for this location yet.</div>
                            @endforelse
                        </div>
                    </section>
                @empty
                    <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center text-zinc-500">No public locations are available yet.</div>
                @endforelse
            </div>
        </section>

        <section class="border-t border-zinc-200 bg-white">
            <div class="mx-auto grid max-w-6xl gap-8 px-5 py-10 md:grid-cols-2">
                <div>
                    <h2 class="text-xl font-semibold">About {{ $tenant->company_name }}</h2>
                    <p class="mt-3 leading-7 text-zinc-600">{{ $tenant->public_site_about ?: 'This hotspot operator uses HotspotFreeRAD to manage plans, routers, and customer access through MikroTik and FreeRADIUS.' }}</p>
                </div>

                <div>
                    <h2 class="text-xl font-semibold">Contact</h2>
                    <dl class="mt-3 space-y-3 text-sm text-zinc-600">
                        @if ($tenant->contact_phone)
                            <div><dt class="font-medium text-zinc-950">Phone</dt><dd>{{ $tenant->contact_phone }}</dd></div>
                        @endif
                        @if ($tenant->contact_email)
                            <div><dt class="font-medium text-zinc-950">Email</dt><dd>{{ $tenant->contact_email }}</dd></div>
                        @endif
                        @if ($tenant->contact_address)
                            <div><dt class="font-medium text-zinc-950">Address</dt><dd>{{ $tenant->contact_address }}</dd></div>
                        @endif
                    </dl>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
