<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tenant->company_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-zinc-950 antialiased" style="--brand: {{ $tenant->brand_color ?? '#0f766e' }}">
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

    <main>
        <section class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-5">
                <a href="{{ route('tenant.public-site', $tenant) }}" class="flex min-w-0 items-center gap-3">
                    @if ($logoImageUrl)
                        <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 shrink-0 rounded-lg border border-zinc-200 bg-white object-cover">
                    @else
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">
                            {{ str($tenant->company_name)->substr(0, 1)->upper() }}
                        </span>
                    @endif
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">{{ $tenant->company_name }}</span>
                        <span class="block truncate text-xs text-zinc-500">Public hotspot site</span>
                    </span>
                </a>

                <div class="flex items-center gap-2">
                    <a href="#plans" class="hidden rounded-md border border-zinc-200 px-3 py-2 text-sm font-medium hover:bg-zinc-50 sm:inline-flex">Plans</a>
                    @auth
                        <a href="{{ route('redirect-after-login') }}" class="rounded-md bg-zinc-950 px-3 py-2 text-sm font-medium text-white">Open dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-md bg-zinc-950 px-3 py-2 text-sm font-medium text-white">Admin sign in</a>
                    @endauth
                </div>
            </div>

            <div class="mx-auto grid min-h-[72vh] max-w-6xl content-center gap-10 px-5 py-10 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
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

                    <dl class="mt-8 grid max-w-2xl grid-cols-3 gap-3">
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <dt class="text-xs text-zinc-500">Locations</dt>
                            <dd class="mt-2 text-2xl font-semibold">{{ number_format($publicStats['locations']) }}</dd>
                        </div>
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <dt class="text-xs text-zinc-500">Plans</dt>
                            <dd class="mt-2 text-2xl font-semibold">{{ number_format($publicStats['plans']) }}</dd>
                        </div>
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <dt class="text-xs text-zinc-500">From</dt>
                            <dd class="mt-2 text-lg font-semibold">{{ $publicStats['starting_price'] === null ? 'Soon' : $publicStats['currency'].' '.number_format($publicStats['starting_price'], 0) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="overflow-hidden rounded-lg border border-zinc-200 bg-zinc-950 text-white shadow-sm">
                    @if ($heroImageUrl)
                        <img src="{{ $heroImageUrl }}" alt="{{ $tenant->company_name }} hero" class="h-56 w-full object-cover">
                    @endif

                    <div class="p-6">
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
            </div>
        </section>

        @if ($slideImageUrls->isNotEmpty())
            <section class="border-b border-zinc-200 bg-white">
                <div class="mx-auto max-w-6xl px-5 py-10">
                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                        <div>
                            <p class="text-sm font-medium" style="color: var(--brand)">Network gallery</p>
                            <h2 class="mt-2 text-2xl font-semibold">Latest offers and locations</h2>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 {{ $slideImageUrls->count() === 1 ? 'md:grid-cols-1' : ($slideImageUrls->count() === 2 ? 'md:grid-cols-2' : 'md:grid-cols-3') }}">
                        @foreach ($slideImageUrls as $slideImageUrl)
                            <img src="{{ $slideImageUrl }}" alt="{{ $tenant->company_name }} gallery image {{ $loop->iteration }}" class="h-64 w-full rounded-lg border border-zinc-200 object-cover">
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        @if ($featuredPackages->isNotEmpty())
            <section class="border-b border-zinc-200 bg-zinc-950 text-white">
                <div class="mx-auto max-w-6xl px-5 py-10">
                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                        <div>
                            <p class="text-sm font-medium text-zinc-400">Featured access</p>
                            <h2 class="mt-2 text-2xl font-semibold">Popular plans</h2>
                        </div>
                        <a href="#plans" class="text-sm font-medium text-zinc-300 hover:text-white">See every location</a>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-3">
                        @foreach ($featuredPackages as $package)
                            <article class="rounded-lg border border-white/10 bg-white/[0.04] p-5">
                                <p class="text-sm text-zinc-400">{{ $package->shop->name }}</p>
                                <h3 class="mt-2 text-lg font-semibold">{{ $package->name }}</h3>
                                <p class="mt-4 text-3xl font-semibold">{{ $package->currency }} {{ number_format($package->price, 2) }}</p>
                                <dl class="mt-5 space-y-2 text-sm text-zinc-300">
                                    <div class="flex justify-between gap-4"><dt>Uptime</dt><dd>{{ $formatDuration($package->limit_uptime_seconds) }}</dd></div>
                                    <div class="flex justify-between gap-4"><dt>Data</dt><dd>{{ $formatData($package->data_limit_bytes) }}</dd></div>
                                    <div class="flex justify-between gap-4"><dt>Speed</dt><dd>{{ $package->speed_limit_profile }}</dd></div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

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
                                            <dd class="font-medium text-zinc-950">{{ $formatDuration($package->limit_uptime_seconds) }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-4">
                                            <dt>Data</dt>
                                            <dd class="font-medium text-zinc-950">{{ $formatData($package->data_limit_bytes) }}</dd>
                                        </div>
                                        @if ($package->fup_data_threshold_bytes && $package->fup_speed_limit_profile)
                                            <div class="flex justify-between gap-4">
                                                <dt>Fair use</dt>
                                                <dd class="text-right font-medium text-zinc-950">After {{ $formatData($package->fup_data_threshold_bytes) }}: {{ $package->fup_speed_limit_profile }}</dd>
                                            </div>
                                        @endif
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
            <div class="mx-auto grid max-w-6xl gap-8 px-5 py-10 {{ $flyerImageUrl ? 'md:grid-cols-3' : 'md:grid-cols-2' }}">
                @if ($flyerImageUrl)
                    <div>
                        <img src="{{ $flyerImageUrl }}" alt="{{ $tenant->company_name }} flyer" class="aspect-[4/5] w-full rounded-lg border border-zinc-200 object-cover">
                    </div>
                @endif

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
