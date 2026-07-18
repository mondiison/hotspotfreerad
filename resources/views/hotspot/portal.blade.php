<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shop->name }} Hotspot</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $shop->tenant->brand_color ?? '#10b981' }}">
    @php
        $tenant = $shop->tenant;
        $heroImageUrl = $tenant->hero_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->hero_image_path) : null;
        $flyerImageUrl = $tenant->flyer_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->flyer_image_path) : null;
        $slideImageUrls = collect($tenant->public_site_slides ?? [])
            ->map(fn ($path) => \Illuminate\Support\Facades\Storage::disk('public')->url($path));

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
    @endphp

    <main class="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-5 py-8">
        <header class="flex items-start justify-between gap-4 border-b border-white/10 pb-5">
            <div>
                <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
                <h1 class="mt-1 text-3xl font-semibold">{{ $shop->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-zinc-300">{{ $tenant->public_site_tagline ?: 'Choose an internet plan for this device.' }}</p>
                <p class="mt-2 text-sm text-zinc-400">Device {{ $macAddress }}</p>
            </div>
            <div class="rounded-md border border-white/10 px-3 py-2 text-right text-xs text-zinc-300">
                <p>{{ $router->nas_identifier }}</p>
                <p>{{ $router->wireguard_internal_ip }}</p>
            </div>
        </header>

        @if ($heroImageUrl || $flyerImageUrl || $slideImageUrls->isNotEmpty())
            <section class="grid gap-4 border-b border-white/10 py-6 lg:grid-cols-[1.4fr_0.8fr]">
                @if ($heroImageUrl)
                    <img src="{{ $heroImageUrl }}" alt="{{ $tenant->company_name }} hero" class="h-64 w-full rounded-lg border border-white/10 object-cover">
                @else
                    <div class="rounded-lg border border-white/10 bg-white/[0.04] p-6">
                        <p class="text-sm font-medium" style="color: var(--brand)">Welcome</p>
                        <p class="mt-3 text-2xl font-semibold">{{ $tenant->public_site_tagline ?: 'Internet access for this hotspot.' }}</p>
                    </div>
                @endif

                <div class="grid gap-4">
                    @if ($flyerImageUrl)
                        <img src="{{ $flyerImageUrl }}" alt="{{ $tenant->company_name }} flyer" class="h-64 w-full rounded-lg border border-white/10 object-cover">
                    @endif

                    @if ($slideImageUrls->isNotEmpty())
                        <div class="grid gap-3 {{ $flyerImageUrl ? 'grid-cols-2' : 'grid-cols-3' }}">
                            @foreach ($slideImageUrls->take($flyerImageUrl ? 2 : 3) as $slideImageUrl)
                                <img src="{{ $slideImageUrl }}" alt="{{ $tenant->company_name }} offer {{ $loop->iteration }}" class="h-24 w-full rounded-lg border border-white/10 object-cover">
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @endif

        <section class="py-8">
            <h2 class="text-lg font-semibold">Choose internet access</h2>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                @forelse ($packages as $package)
                    <article class="rounded-lg border border-white/10 bg-white p-5 text-zinc-950">
                        <h3 class="text-lg font-semibold">{{ $package->name }}</h3>
                        <p class="mt-3 text-3xl font-semibold">{{ $package->currency }} {{ number_format($package->price, 2) }}</p>
                        <dl class="mt-4 space-y-2 text-sm text-zinc-600">
                            <div class="flex justify-between gap-4">
                                <dt>Speed</dt>
                                <dd class="font-medium text-zinc-950">{{ $package->speed_limit_profile }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Time</dt>
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
                        <form method="POST" action="{{ route('hotspot.pay') }}" class="mt-5 space-y-3">
                            @csrf
                            <input type="hidden" name="package_id" value="{{ $package->id }}">
                            <input type="hidden" name="mac" value="{{ $macAddress }}">
                            <input type="hidden" name="nasid" value="{{ $router->nas_identifier }}">
                            <input type="email" name="email" placeholder="Email for receipt" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                            <input name="phone" placeholder="Phone number" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                            @if ($loginUrl)
                                <input type="hidden" name="link-login" value="{{ $loginUrl }}">
                            @endif
                            @if ($originalUrl)
                                <input type="hidden" name="link-orig" value="{{ $originalUrl }}">
                            @endif
                            <button class="w-full rounded-md px-4 py-2 text-sm font-medium text-white" style="background-color: var(--brand)">
                                Continue to payment
                            </button>
                        </form>

                        <form method="POST" action="{{ route('hotspot.grant') }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="package_id" value="{{ $package->id }}">
                            <input type="hidden" name="mac" value="{{ $macAddress }}">
                            <input type="hidden" name="nasid" value="{{ $router->nas_identifier }}">
                            @if ($loginUrl)
                                <input type="hidden" name="link-login" value="{{ $loginUrl }}">
                            @endif
                            @if ($originalUrl)
                                <input type="hidden" name="link-orig" value="{{ $originalUrl }}">
                            @endif
                            <button class="w-full rounded-md border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700">
                                Start test access
                            </button>
                        </form>
                    </article>
                @empty
                    <div class="rounded-lg border border-white/10 bg-white/5 p-5 text-sm text-zinc-300 md:col-span-3">
                        No active packages are available for this hotspot yet.
                    </div>
                @endforelse
            </div>
        </section>

        <footer class="mt-auto border-t border-white/10 pt-5 text-xs text-zinc-400">
            <p>Powered by HotspotFreeRAD.</p>
        </footer>
    </main>
</body>
</html>
