<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Voucher Purchased</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
@php
    $tenant = $router?->shop?->tenant;
    $brandColor = $tenant?->brand_color ?? '#10b981';
    $flyerImageUrl = $tenant?->flyer_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->flyer_image_path) : null;

    $formatDuration = function (?int $seconds): string {
        if (! $seconds) {
            return '0 minutes';
        }

        if ($seconds >= 86400) {
            $days = (int) round($seconds / 86400);

            return $days.' '.\Illuminate\Support\Str::plural('day', $days);
        }

        if ($seconds >= 3600) {
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            return $hours.'h '.$minutes.'m';
        }

        $minutes = (int) max(1, round($seconds / 60));

        return $minutes.' '.\Illuminate\Support\Str::plural('minute', $minutes);
    };
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $brandColor }}" x-data="{ copied: false }">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="overflow-hidden rounded-lg border border-white/10 bg-white text-zinc-950">
            @if ($flyerImageUrl)
                <img src="{{ $flyerImageUrl }}" alt="{{ $tenant->company_name }} flyer" class="h-56 w-full object-cover">
            @endif

            <div class="p-6">
                <p class="text-sm font-medium" style="color: var(--brand)">Payment received</p>
                <h1 class="mt-2 text-2xl font-semibold">Here's your voucher code.</h1>
                <p class="mt-2 text-sm text-zinc-600">
                    This wasn't connected to this device -- it's a code you can save, use later, or give to another device/person. Nothing is charged again when it's redeemed.
                </p>

                <div class="mt-5 flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-zinc-50 px-4 py-4">
                    <span class="font-mono text-2xl font-semibold tracking-wide">{{ $voucher->code }}</span>
                    <button
                        type="button"
                        class="flex shrink-0 items-center gap-2 rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700"
                        @click="copied = await window.copyText(@js($voucher->code)); setTimeout(() => copied = false, 1800)"
                    >
                        <span x-show="! copied">Copy</span>
                        <span x-cloak x-show="copied">Copied</span>
                    </button>
                </div>

                <dl class="mt-5 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Package</dt>
                        <dd class="font-medium">{{ $package->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Gets you</dt>
                        <dd class="font-medium">{{ $formatDuration($package->limit_uptime_seconds) }} on {{ $package->speed_limit_profile }}</dd>
                    </div>
                </dl>

                <div class="mt-6 rounded-md bg-zinc-100 p-4 text-sm text-zinc-700">
                    To redeem: connect to this hotspot on any device, open the "Have a voucher?" box on the portal, and enter this code.
                </div>
            </div>
        </section>
    </main>
</body>
</html>
