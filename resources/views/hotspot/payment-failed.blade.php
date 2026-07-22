<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Not Confirmed</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
@php
    $tenant = $payment->shop?->tenant;
    $brandColor = $tenant?->brand_color ?? '#ef4444';
    $whatsappMessage = rawurlencode(
        "Hello MMS Radius support, my hotspot payment was debited but access was not activated.\n\n".
        "Shop: ".($payment->shop?->name ?? 'Unknown')."\n".
        "Package: ".($payment->package?->name ?? 'Unknown')."\n".
        "Amount: {$payment->currency} ".number_format((float) $payment->amount, 2)."\n".
        "Transaction reference: {$payment->tx_ref}\n".
        "Provider reference: ".($payment->provider_reference ?: 'Not available')."\n".
        "Status: {$payment->status}"
    );
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $brandColor }}">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant?->company_name ?? 'HotspotFreeRAD' }}</p>
            <p class="mt-2 text-sm font-medium text-red-600">Payment not confirmed</p>
            <h1 class="mt-2 text-2xl font-semibold">We could not activate this internet package yet.</h1>
            @if ($statusMessage ?? null)
                <div class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">{{ $statusMessage }}</div>
            @endif
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

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <form method="POST" action="{{ route('hotspot.payment.verify') }}" x-data="{ verifying: false }" @submit="verifying = true">
                    @csrf
                    <input type="hidden" name="tx_ref" value="{{ $payment->tx_ref }}">
                    <button class="flex w-full items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white disabled:cursor-wait disabled:opacity-75" style="background-color: var(--brand)" :disabled="verifying">
                        <span x-show="verifying" x-cloak class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                        <span x-text="verifying ? 'Verifying payment...' : 'Verify and connect'">Verify and connect</span>
                    </button>
                </form>

                <a
                    href="https://wa.me/2347063218823?text={{ $whatsappMessage }}"
                    class="inline-flex items-center justify-center rounded-md border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-50"
                    target="_blank"
                    rel="noopener"
                >
                    Message support on WhatsApp
                </a>
            </div>
        </section>
    </main>
</body>
</html>
