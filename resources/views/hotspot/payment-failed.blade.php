<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Not Confirmed</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $tenant = $payment->shop?->tenant;
    $brandColor = $tenant?->brand_color ?? '#ef4444';
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $brandColor }}">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant?->company_name ?? 'HotspotFreeRAD' }}</p>
            <p class="mt-2 text-sm font-medium text-red-600">Payment not confirmed</p>
            <h1 class="mt-2 text-2xl font-semibold">We could not activate this internet package yet.</h1>
            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Transaction reference</dt>
                    <dd class="font-mono text-xs font-medium">{{ $payment->tx_ref }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Status</dt>
                    <dd class="font-medium">{{ str_replace('_', ' ', $payment->status) }}</dd>
                </div>
            </dl>
            <div class="mt-6 rounded-md bg-zinc-100 p-4 text-sm text-zinc-700">
                If money was debited, contact the hotspot operator with this transaction reference.
            </div>
        </section>
    </main>
</body>
</html>
