<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Access - {{ $shop->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $tenant = $shop->tenant;
    $brandColor = $tenant?->brand_color ?? '#ef4444';
    $logoImageUrl = $tenant?->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
    $isExpired = $device && ! $device->isCurrentlyActive();
    $whatsappMessage = rawurlencode(
        "Hello, my POS device's internet access isn't working.\n\n".
        "Shop: {$shop->name}\n".
        "Device: ".($device?->device_name ?: 'Unnamed device')."\n".
        "MAC address: {$macAddress}\n".
        ($device?->expires_at ? "Expires: {$device->expires_at->format('M j, Y g:i A')}\n" : '').
        'Status: '.($device ? ($isExpired ? 'Expired/inactive' : 'Active') : 'Not registered')
    );
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $brandColor }}">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <div class="flex items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-cover">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">{{ str($tenant?->company_name ?? $shop->name)->substr(0, 1)->upper() }}</span>
                @endif
                <div>
                    <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant?->company_name ?? $shop->name }}</p>
                    <p class="text-xs text-zinc-500">{{ $shop->name }}</p>
                </div>
            </div>

            @if (! $device)
                <p class="mt-4 text-sm font-medium text-red-600">Not allowed on this network</p>
                <h1 class="mt-2 text-2xl font-semibold">This device is not registered for POS access.</h1>
                <p class="mt-2 text-sm text-zinc-600">Ask your administrator to register this device's MAC address before connecting it here.</p>
            @elseif ($isExpired)
                <p class="mt-4 text-sm font-medium text-red-600">POS package expired</p>
                <h1 class="mt-2 text-2xl font-semibold">This POS device's package has expired.</h1>
                <p class="mt-2 text-sm text-zinc-600">Internet access for this terminal has been suspended. Contact your administrator to renew it.</p>
            @else
                <p class="mt-4 text-sm font-medium text-amber-600">Reconnecting</p>
                <h1 class="mt-2 text-2xl font-semibold">This POS device is registered but not connected yet.</h1>
                <p class="mt-2 text-sm text-zinc-600">Access should resume shortly. If this page keeps showing up, contact your administrator.</p>
            @endif

            <dl class="mt-5 space-y-3 text-sm">
                @if ($device)
                    <div>
                        <dt class="text-zinc-500">Device</dt>
                        <dd class="font-medium">{{ $device->device_name ?: 'Unnamed device' }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-zinc-500">MAC address</dt>
                    <dd class="font-mono text-xs font-medium">{{ $macAddress }}</dd>
                </div>
                @if ($device?->package)
                    <div>
                        <dt class="text-zinc-500">Package</dt>
                        <dd class="font-medium">{{ $device->package->name }}</dd>
                    </div>
                @endif
                @if ($device?->expires_at)
                    <div>
                        <dt class="text-zinc-500">{{ $isExpired ? 'Expired' : 'Expires' }}</dt>
                        <dd class="font-medium">{{ $device->expires_at->format('M j, Y g:i A') }}</dd>
                    </div>
                @endif
            </dl>

            <div class="mt-6 rounded-md bg-zinc-100 p-4 text-sm text-zinc-700">
                This SSID/port is for registered POS terminals only. Renewals and new registrations are handled by your administrator, not through this page.
            </div>

            <div class="mt-6">
                <a
                    href="https://wa.me/2347063218823?text={{ $whatsappMessage }}"
                    class="inline-flex w-full items-center justify-center rounded-md border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-50 sm:w-auto"
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
