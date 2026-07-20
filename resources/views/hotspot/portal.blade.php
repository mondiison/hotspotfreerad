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
        $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
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

    <main class="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-3 py-4 sm:px-5 sm:py-8">
        <header class="flex items-start justify-between gap-3 border-b border-white/10 pb-4 sm:gap-4 sm:pb-5">
            <div class="flex min-w-0 gap-3 sm:gap-4">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-11 w-11 shrink-0 rounded-lg border border-white/10 bg-white object-cover sm:h-14 sm:w-14">
                @else
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg text-base font-semibold text-white sm:h-14 sm:w-14 sm:text-lg" style="background-color: var(--brand)">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                @endif
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
                    <h1 class="mt-1 truncate text-xl font-semibold sm:text-3xl">{{ $shop->name }}</h1>
                    <p class="mt-1 max-w-2xl text-xs text-zinc-300 sm:mt-2 sm:text-sm">{{ $tenant->public_site_tagline ?: 'Choose an internet plan for this device.' }}</p>
                    <p class="mt-1 text-xs text-zinc-400 sm:mt-2 sm:text-sm">Device {{ $macAddress }}</p>
                </div>
            </div>
            <div class="hidden rounded-md border border-white/10 px-3 py-2 text-right text-xs text-zinc-300 sm:block">
                <p>{{ $router->nas_identifier }}</p>
                <p>{{ $router->wireguard_internal_ip }}</p>
            </div>
        </header>

        @if ($heroImageUrl || $flyerImageUrl || $slideImageUrls->isNotEmpty())
            <section class="grid gap-3 border-b border-white/10 py-4 sm:gap-4 sm:py-6 lg:grid-cols-[1.4fr_0.8fr]">
                @if ($heroImageUrl)
                    <img src="{{ $heroImageUrl }}" alt="{{ $tenant->company_name }} hero" class="h-28 w-full rounded-lg border border-white/10 object-cover sm:h-64">
                @else
                    <div class="rounded-lg border border-white/10 bg-white/[0.04] p-4 sm:p-6">
                        <p class="text-sm font-medium" style="color: var(--brand)">Welcome</p>
                        <p class="mt-2 text-lg font-semibold sm:mt-3 sm:text-2xl">{{ $tenant->public_site_tagline ?: 'Internet access for this hotspot.' }}</p>
                    </div>
                @endif

                <div class="grid gap-3 sm:gap-4">
                    @if ($flyerImageUrl)
                        <img src="{{ $flyerImageUrl }}" alt="{{ $tenant->company_name }} flyer" class="h-28 w-full rounded-lg border border-white/10 object-cover sm:h-64">
                    @endif

                    @if ($slideImageUrls->isNotEmpty())
                        <div class="grid gap-3 {{ $flyerImageUrl ? 'grid-cols-2' : 'grid-cols-3' }}">
                            @foreach ($slideImageUrls->take($flyerImageUrl ? 2 : 3) as $slideImageUrl)
                                <img src="{{ $slideImageUrl }}" alt="{{ $tenant->company_name }} offer {{ $loop->iteration }}" class="h-16 w-full rounded-lg border border-white/10 object-cover sm:h-24">
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @endif

        <section class="py-4 sm:py-8">
            <h2 class="text-lg font-semibold">Choose internet access</h2>

            <div class="mt-3 grid gap-3 sm:mt-5 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($packages as $package)
                    <article class="rounded-lg border border-white/10 bg-white p-3 text-zinc-950 shadow-sm sm:p-5">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="min-w-0 text-base font-semibold sm:text-lg">{{ $package->name }}</h3>
                            <p class="shrink-0 text-right text-lg font-semibold sm:text-3xl">{{ $package->currency }} {{ number_format($package->price, 0) }}</p>
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-xs text-zinc-600 sm:mt-4 sm:block sm:space-y-2 sm:text-sm">
                            <div class="rounded-md bg-zinc-50 p-2 sm:flex sm:justify-between sm:gap-4 sm:bg-transparent sm:p-0">
                                <dt>Speed</dt>
                                <dd class="mt-1 font-medium text-zinc-950 sm:mt-0">{{ $package->speed_limit_profile }}</dd>
                            </div>
                            <div class="rounded-md bg-zinc-50 p-2 sm:flex sm:justify-between sm:gap-4 sm:bg-transparent sm:p-0">
                                <dt>Time</dt>
                                <dd class="mt-1 font-medium text-zinc-950 sm:mt-0">{{ $formatDuration($package->limit_uptime_seconds) }}</dd>
                            </div>
                            <div class="rounded-md bg-zinc-50 p-2 sm:flex sm:justify-between sm:gap-4 sm:bg-transparent sm:p-0">
                                <dt>Data</dt>
                                <dd class="mt-1 font-medium text-zinc-950 sm:mt-0">{{ $formatData($package->data_limit_bytes) }}</dd>
                            </div>
                            @if ($package->fup_data_threshold_bytes && $package->fup_speed_limit_profile)
                                <div class="col-span-3 rounded-md bg-zinc-50 p-2 sm:flex sm:justify-between sm:gap-4 sm:bg-transparent sm:p-0">
                                    <dt>Fair use</dt>
                                    <dd class="mt-1 font-medium text-zinc-950 sm:mt-0 sm:text-right">After {{ $formatData($package->fup_data_threshold_bytes) }}: {{ $package->fup_speed_limit_profile }}</dd>
                                </div>
                            @endif
                        </dl>
                        <form method="POST" action="{{ route('hotspot.pay') }}" class="mt-3 space-y-2 sm:mt-5 sm:space-y-3">
                            @csrf
                            <input type="hidden" name="package_id" value="{{ $package->id }}">
                            <input type="hidden" name="mac" value="{{ $macAddress }}">
                            <input type="hidden" name="nasid" value="{{ $router->nas_identifier }}">
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-1 sm:gap-3">
                                <input type="email" name="email" placeholder="Email" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                                <input name="phone" placeholder="Phone" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                            </div>
                            <fieldset>
                                <legend class="mb-2 text-xs font-medium text-zinc-500">Pay with</legend>
                                <div class="grid grid-cols-3 gap-2">
                                    @foreach ([['opay', 'OPay', true, true], ['bank_transfer', 'Transfer', true, false], ['card', 'Card', false, false]] as [$methodValue, $methodLabel, $methodAvailable, $methodSelected])
                                        <label class="cursor-pointer">
                                            <input type="radio" name="payment_method" value="{{ $methodValue }}" class="peer sr-only" @checked($methodSelected) @disabled(! $methodAvailable)>
                                            <span class="grid min-h-9 place-items-center rounded-md border border-zinc-200 px-2 text-center text-xs font-medium text-zinc-600 transition peer-checked:border-zinc-950 peer-checked:bg-zinc-950 peer-checked:text-white peer-disabled:cursor-not-allowed peer-disabled:bg-zinc-50 peer-disabled:text-zinc-400">
                                                <span>{{ $methodLabel }}</span>
                                                @unless ($methodAvailable)
                                                    <span class="text-[10px] font-normal">Soon</span>
                                                @endunless
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            @if ($loginUrl)
                                <input type="hidden" name="link-login" value="{{ $loginUrl }}">
                            @endif
                            @if ($originalUrl)
                                <input type="hidden" name="link-orig" value="{{ $originalUrl }}">
                            @endif
                            <button class="w-full rounded-md px-3 py-2 text-sm font-medium text-white" style="background-color: var(--brand)">
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
                            <button class="w-full rounded-md border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700">
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
