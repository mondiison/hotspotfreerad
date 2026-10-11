<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tenant->company_name }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white font-sans text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-100" style="--brand: {{ $tenant->brand_color ?? '#0f766e' }}">
    @php
        $formatDuration = function (?int $seconds): string {
            if (! $seconds) {
                return 'Not set';
            }

            if ($seconds >= 86400) {
                $days = (int) round($seconds / 86400);

                return $days.' '.\Illuminate\Support\Str::plural('day', $days);
            }

            if ($seconds >= 3600) {
                $hours = (int) round($seconds / 3600);

                return $hours.' '.\Illuminate\Support\Str::plural('hour', $hours);
            }

            $minutes = (int) max(1, round($seconds / 60));

            return $minutes.' '.\Illuminate\Support\Str::plural('minute', $minutes);
        };

        $formatData = function (?int $bytes): string {
            if (! $bytes) {
                return 'Unlimited';
            }

            return number_format($bytes / 1073741824, $bytes % 1073741824 === 0 ? 0 : 1).' GB';
        };

        $heroImageUrl = $tenant->hero_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->hero_image_path) : null;
        $flyerImageUrl = $tenant->flyer_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->flyer_image_path) : null;
        $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
        $slideImageUrls = collect($tenant->public_site_slides ?? [])
            ->map(fn ($path) => \Illuminate\Support\Facades\Storage::disk('public')->url($path));
    @endphp

    {{-- Nav --}}
    <header class="sticky top-0 z-40 border-b border-zinc-200/80 bg-white/80 backdrop-blur-lg dark:border-white/10 dark:bg-zinc-950/80">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <a href="{{ route('tenant.public-site', $tenant) }}" class="flex min-w-0 items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 shrink-0 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 object-cover">
                @else
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">
                        {{ str($tenant->company_name)->substr(0, 1)->upper() }}
                    </span>
                @endif
                <span class="min-w-0">
                    <span class="block truncate font-semibold">{{ $tenant->company_name }}</span>
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">Public hotspot site</span>
                </span>
            </a>

            <div class="flex items-center gap-2">
                <a href="#plans" class="hidden rounded-lg border border-zinc-200 dark:border-zinc-700 px-4 py-2 text-sm font-semibold transition hover:bg-zinc-50 dark:hover:bg-zinc-800 sm:inline-flex">Plans</a>
                @auth
                    <a href="{{ route('redirect-after-login') }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90" style="background-color: var(--brand)">Open dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90" style="background-color: var(--brand)">Admin sign in</a>
                @endauth
            </div>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden bg-zinc-950">
            <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(55rem 28rem at 10% -10%, color-mix(in oklab, var(--brand), transparent 80%), transparent), radial-gradient(45rem 25rem at 95% 15%, color-mix(in oklab, var(--brand), transparent 88%), transparent);"></div>

            <div class="relative mx-auto grid gap-10 px-5 py-16 sm:px-8 sm:py-20 lg:min-h-[70vh] lg:grid-cols-[1.05fr_0.95fr] lg:items-center lg:py-0">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-zinc-300">
                        <span class="h-1.5 w-1.5 rounded-full" style="background-color: var(--brand)"></span>
                        {{ $tenant->subscription_plan }} hotspot network
                    </span>

                    <h1 class="mt-5 max-w-3xl text-4xl font-bold leading-[1.1] tracking-tight text-white md:text-6xl">{{ $tenant->company_name }}</h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-zinc-400">
                        {{ $tenant->public_site_tagline ?: 'Simple, reliable internet access for customers across active hotspot locations.' }}
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="#plans" class="inline-flex items-center gap-2 rounded-lg px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-black/20 transition hover:opacity-90" style="background-color: var(--brand)">View internet plans</a>
                        @if ($tenant->contact_phone)
                            <a href="tel:{{ $tenant->contact_phone }}" class="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-6 py-3.5 text-sm font-semibold text-white transition hover:bg-white/10">Call support</a>
                        @endif
                    </div>

                    <dl class="mt-10 grid max-w-2xl grid-cols-3 gap-3">
                        <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                            <dt class="text-xs text-zinc-500">Locations</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">{{ number_format($publicStats['locations']) }}</dd>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                            <dt class="text-xs text-zinc-500">Plans</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">{{ number_format($publicStats['plans']) }}</dd>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                            <dt class="text-xs text-zinc-500">From</dt>
                            <dd class="mt-2 text-lg font-semibold text-white">{{ $publicStats['starting_price'] === null ? 'Soon' : $publicStats['currency'].' '.number_format($publicStats['starting_price'], 0) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="relative">
                    <div class="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-white/10 to-transparent blur-2xl"></div>
                    <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-zinc-900/80 shadow-2xl shadow-black/40 backdrop-blur">
                        @if ($heroImageUrl)
                            <img src="{{ $heroImageUrl }}" alt="{{ $tenant->company_name }} hero" class="h-56 w-full object-cover">
                        @endif

                        <div class="p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm text-zinc-500">Network locations</p>
                                    <p class="mt-2 text-4xl font-semibold text-white">{{ $tenant->shops->count() }}</p>
                                </div>
                                <span class="rounded-full px-3 py-1.5 text-xs font-semibold text-white" style="background-color: var(--brand)">Live</span>
                            </div>

                            <div class="mt-8 space-y-4">
                                @forelse ($tenant->shops->take(3) as $shop)
                                    <div class="border-t border-white/10 pt-4">
                                        <p class="font-medium text-white">{{ $shop->name }}</p>
                                        <p class="mt-1 text-sm text-zinc-500">{{ $shop->packages->count() }} active {{ \Illuminate\Support\Str::plural('plan', $shop->packages->count()) }}{{ $shop->location_city ? ' in '.$shop->location_city : '' }}</p>
                                    </div>
                                @empty
                                    <div class="border-t border-white/10 pt-4 text-sm text-zinc-500">No public hotspot locations have been added yet.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @if ($slideImageUrls->isNotEmpty())
            <section class="border-b border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-950">
                <div class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand)">Network gallery</p>
                            <h2 class="mt-2 text-2xl font-bold tracking-tight">Latest offers and locations</h2>
                        </div>
                    </div>

                    <div class="mt-7 grid gap-4 {{ $slideImageUrls->count() === 1 ? 'md:grid-cols-1' : ($slideImageUrls->count() === 2 ? 'md:grid-cols-2' : 'md:grid-cols-3') }}">
                        @foreach ($slideImageUrls as $slideImageUrl)
                            <img src="{{ $slideImageUrl }}" alt="{{ $tenant->company_name }} gallery image {{ $loop->iteration }}" class="h-64 w-full rounded-xl border border-zinc-200 dark:border-zinc-700 object-cover shadow-sm transition hover:shadow-md">
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        @if ($featuredPackages->isNotEmpty())
            <section class="border-b border-zinc-200 bg-zinc-950 text-white dark:border-white/10">
                <div class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wider text-zinc-400">Featured access</p>
                            <h2 class="mt-2 text-2xl font-bold tracking-tight">Popular plans</h2>
                        </div>
                        <a href="#plans" class="text-sm font-medium text-zinc-400 transition hover:text-white">See every location</a>
                    </div>

                    <div class="mt-7 grid gap-4 md:grid-cols-3">
                        @foreach ($featuredPackages as $package)
                            <article class="rounded-xl border border-white/10 bg-white/[0.04] p-5 transition hover:border-white/20 hover:bg-white/[0.06]">
                                <p class="text-sm text-zinc-400">{{ $package->shop->name }}</p>
                                <h3 class="mt-2 text-lg font-semibold">{{ $package->name }}</h3>
                                <p class="mt-4 text-3xl font-bold tracking-tight">{{ $package->currency }} {{ number_format($package->price, 2) }}</p>
                                <dl class="mt-5 space-y-2 text-sm text-zinc-300">
                                    <div class="flex justify-between gap-4"><dt>Uptime</dt><dd>{{ $formatDuration($package->limit_uptime_seconds) }}</dd></div>
                                    <div class="flex justify-between gap-4"><dt>Data</dt><dd>{{ $formatData($package->data_limit_bytes) }}</dd></div>
                                    <div class="flex justify-between gap-4"><dt>Speed</dt><dd>{{ $package->speed_limit_profile }}</dd></div>
                                    @if ($package->fup_data_threshold_bytes && $package->fup_speed_limit_profile)
                                        <div class="flex justify-between gap-4"><dt>Fair use</dt><dd class="text-right">After {{ $formatData($package->fup_data_threshold_bytes) }}: {{ $package->fup_speed_limit_profile }}</dd></div>
                                    @endif
                                </dl>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section id="plans" class="mx-auto max-w-6xl px-5 py-16 sm:px-8">
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight">Available Internet Access</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-400">Plans are shown by location. When customers connect to the Wi-Fi, the MikroTik captive portal sends them to the matching login page automatically.</p>
                </div>
            </div>

            <div class="mt-7 space-y-6">
                @forelse ($tenant->shops as $shop)
                    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                        <div class="flex flex-col justify-between gap-2 md:flex-row md:items-center">
                            <div>
                                <h3 class="text-lg font-semibold">{{ $shop->name }}</h3>
                                @if ($shop->location_city)
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $shop->location_city }}</p>
                                @endif
                            </div>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $shop->packages->count() }} active {{ \Illuminate\Support\Str::plural('plan', $shop->packages->count()) }}</span>
                        </div>

                        <div class="mt-6 grid gap-4 md:grid-cols-3">
                            @forelse ($shop->packages as $package)
                                <article class="rounded-xl border border-zinc-200 p-5 transition hover:border-zinc-300 hover:shadow-sm dark:border-white/10 dark:hover:border-white/20">
                                    <h4 class="font-semibold">{{ $package->name }}</h4>
                                    <p class="mt-3 text-2xl font-bold tracking-tight">{{ $package->currency }} {{ number_format($package->price, 2) }}</p>
                                    <dl class="mt-4 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                                        <div class="flex justify-between gap-4">
                                            <dt>Speed</dt>
                                            <dd class="font-medium text-zinc-950 dark:text-zinc-100">{{ $package->speed_limit_profile }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-4">
                                            <dt>Uptime</dt>
                                            <dd class="font-medium text-zinc-950 dark:text-zinc-100">{{ $formatDuration($package->limit_uptime_seconds) }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-4">
                                            <dt>Data</dt>
                                            <dd class="font-medium text-zinc-950 dark:text-zinc-100">{{ $formatData($package->data_limit_bytes) }}</dd>
                                        </div>
                                        @if ($package->fup_data_threshold_bytes && $package->fup_speed_limit_profile)
                                            <div class="flex justify-between gap-4">
                                                <dt>Fair use</dt>
                                                <dd class="text-right font-medium text-zinc-950 dark:text-zinc-100">After {{ $formatData($package->fup_data_threshold_bytes) }}: {{ $package->fup_speed_limit_profile }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </article>
                            @empty
                                <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-4 text-sm text-zinc-500 dark:text-zinc-400 md:col-span-3">No active plans are published for this location yet.</div>
                            @endforelse
                        </div>
                    </section>
                @empty
                    <div class="rounded-2xl border border-zinc-200 bg-white p-8 text-center text-zinc-500 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-400">No public locations are available yet.</div>
                @endforelse
            </div>
        </section>

        <section class="border-t border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-zinc-900/40">
            <div class="mx-auto grid max-w-6xl gap-10 px-5 py-14 sm:px-8 {{ $flyerImageUrl ? 'md:grid-cols-3' : 'md:grid-cols-2' }}">
                @if ($flyerImageUrl)
                    <div>
                        <img src="{{ $flyerImageUrl }}" alt="{{ $tenant->company_name }} flyer" class="aspect-[4/5] w-full rounded-xl border border-zinc-200 dark:border-zinc-700 object-cover shadow-sm">
                    </div>
                @endif

                <div>
                    <h2 class="text-xl font-bold tracking-tight">About {{ $tenant->company_name }}</h2>
                    <p class="mt-3 leading-7 text-zinc-600 dark:text-zinc-400">{{ $tenant->public_site_about ?: 'This hotspot operator uses HotspotFreeRAD to manage plans, routers, and customer access through MikroTik and FreeRADIUS.' }}</p>
                </div>

                <div>
                    <h2 class="text-xl font-bold tracking-tight">Contact</h2>
                    <dl class="mt-3 space-y-3 text-sm text-zinc-600 dark:text-zinc-400">
                        @if ($tenant->contact_phone)
                            <div><dt class="font-medium text-zinc-950 dark:text-zinc-100">Phone</dt><dd>{{ $tenant->contact_phone }}</dd></div>
                        @endif
                        @if ($tenant->contact_email)
                            <div><dt class="font-medium text-zinc-950 dark:text-zinc-100">Email</dt><dd>{{ $tenant->contact_email }}</dd></div>
                        @endif
                        @if ($tenant->contact_address)
                            <div><dt class="font-medium text-zinc-950 dark:text-zinc-100">Address</dt><dd>{{ $tenant->contact_address }}</dd></div>
                        @endif
                    </dl>
                </div>
            </div>
        </section>

        <footer class="border-t border-zinc-200 bg-white py-8 dark:border-white/10 dark:bg-zinc-950">
            <div class="mx-auto max-w-6xl px-5 text-center text-xs text-zinc-500 dark:text-zinc-400 sm:px-8">
                &copy; {{ now()->year }} {{ $tenant->company_name }}. Powered by {{ config('app.name', 'HotspotFreeRAD') }}.
            </div>
        </footer>
    </main>
</body>
</html>
