<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - {{ $shop->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $shop->tenant->brand_color ?? '#10b981' }}">
    @php
        $tenant = $shop->tenant;
        $heroImageUrl = $tenant->hero_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->hero_image_path) : null;
        $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
    @endphp

    <main class="mx-auto flex min-h-screen w-full max-w-4xl flex-col justify-center px-5 py-8">
        <section class="grid overflow-hidden rounded-lg border border-white/10 bg-white text-zinc-950 md:grid-cols-[0.95fr_1.05fr]">
            <div class="hidden bg-zinc-950 p-6 text-white md:flex md:flex-col md:justify-between">
                @if ($heroImageUrl)
                    <img src="{{ $heroImageUrl }}" alt="{{ $tenant->company_name }} hero" class="-mx-6 -mt-6 h-64 w-[calc(100%+3rem)] object-cover">
                @else
                    <div class="rounded-lg border border-white/10 bg-white/[0.04] p-5">
                        <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
                        <p class="mt-3 text-2xl font-semibold">{{ $tenant->public_site_tagline ?: 'Internet access for this hotspot.' }}</p>
                    </div>
                @endif

                <div class="mt-6">
                    <p class="text-sm text-zinc-400">Hotspot location</p>
                    <p class="mt-1 text-xl font-semibold">{{ $shop->name }}</p>
                </div>
            </div>

            <div class="p-6">
            <div class="flex items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-cover">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                @endif
                <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
            </div>
            <h1 class="mt-2 text-2xl font-semibold">Confirm internet access</h1>
            <p class="mt-2 text-sm text-zinc-600">A pending payment has been created, but online payment is not available for this shop yet.</p>

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Package</dt>
                    <dd class="font-medium">{{ $package->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Amount</dt>
                    <dd class="font-medium">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Transaction reference</dt>
                    <dd class="font-mono text-xs font-medium">{{ $payment->tx_ref }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Device</dt>
                    <dd class="font-mono text-xs font-medium">{{ $macAddress }}</dd>
                </div>
            </dl>

            <div class="mt-6 rounded-md bg-amber-50 p-4 text-sm leading-6 text-amber-800">
                This tenant has not connected a Flutterwave payment account for hotspot customers. Use test access while the tenant account is being configured.
            </div>

            <form method="POST" action="{{ route('hotspot.grant') }}" class="mt-5">
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
                <button class="w-full rounded-md px-4 py-2 text-sm font-medium text-white" style="background-color: var(--brand)">
                    Start test access
                </button>
            </form>
            </div>
        </section>
    </main>
</body>
</html>
