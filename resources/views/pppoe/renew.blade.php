<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Renew - {{ $subscriber->shop->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
@php
    $tenant = $subscriber->shop->tenant;
    $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
    $isExpired = $subscriber->expires_at && $subscriber->expires_at->isPast();
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $tenant->brand_color ?? '#10b981' }}">
    <main class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-center px-5 py-8" x-data="{ paying: false }">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <div class="flex items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-cover">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                @endif
                <div>
                    <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
                    <p class="text-xs text-zinc-500">{{ $subscriber->shop->name }}</p>
                </div>
            </div>

            <h1 class="mt-4 text-2xl font-semibold">Renew your internet plan</h1>
            <p class="mt-2 text-sm text-zinc-600">
                @if ($isExpired)
                    Your plan has expired. Pay below to get back online right away.
                @else
                    Your plan is due to expire soon. Pay below to keep your access running with no interruption.
                @endif
            </p>

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Account</dt>
                    <dd class="font-mono font-medium">{{ $subscriber->username }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Plan</dt>
                    <dd class="font-medium">{{ $subscriber->package->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ $isExpired ? 'Expired' : 'Expires' }}</dt>
                    <dd class="font-medium">{{ $subscriber->expires_at?->format('M j, Y g:i A') ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Amount due</dt>
                    <dd class="text-xl font-semibold">{{ $subscriber->package->currency }} {{ number_format($subscriber->package->price, 2) }}</dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('pppoe.renewal.pay', $subscriber->public_token) }}" class="mt-6" @submit="paying = true">
                @csrf
                <button class="flex w-full items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white disabled:cursor-wait disabled:opacity-75" style="background-color: var(--brand)" :disabled="paying">
                    <span x-show="paying" x-cloak class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    <span x-text="paying ? 'Opening payment...' : 'Pay and renew now'">Pay and renew now</span>
                </button>
            </form>

            @if ($subscriber->shop->contactPhone())
                <p class="mt-4 text-center text-xs text-zinc-500">
                    Having trouble? Call {{ $subscriber->shop->contactPhone() }}.
                </p>
            @endif
        </section>
    </main>
</body>
</html>
