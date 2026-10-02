<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shop->name }} Hotspot</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $tenant = $shop->tenant;
    $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $tenant->brand_color ?? '#10b981' }}">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <div class="flex items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-cover">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                @endif
                <div>
                    <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
                    <p class="text-xs text-zinc-500">{{ $shop->name }}</p>
                </div>
            </div>

            <h1 class="mt-4 text-2xl font-semibold">Connect to Wi-Fi first</h1>
            <p class="mt-2 text-sm leading-6 text-zinc-600">
                This link remembers your device once you've connected to the <span class="font-medium text-zinc-950">{{ $shop->name }}</span> hotspot at least once. Join the Wi-Fi, complete the sign-in page that appears, then come back here (or scan this QR code again) to view packages or buy a voucher anytime -- even after your free trial ends.
            </p>

            @if ($shop->contactPhone())
                <a href="tel:{{ $shop->contactPhone() }}" class="mt-4 inline-block text-sm font-medium underline decoration-zinc-300 underline-offset-4" style="color: var(--brand)">
                    Need help? Call {{ $shop->contactPhone() }}
                </a>
            @endif
        </section>
    </main>
</body>
</html>
