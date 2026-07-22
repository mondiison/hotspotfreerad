<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Granted</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
@php
    $tenant = $router?->shop?->tenant;
    $brandColor = $tenant?->brand_color ?? '#10b981';
    $flyerImageUrl = $tenant?->flyer_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->flyer_image_path) : null;
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $brandColor }}">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="overflow-hidden rounded-lg border border-white/10 bg-white text-zinc-950">
            @if ($flyerImageUrl)
                <img src="{{ $flyerImageUrl }}" alt="{{ $tenant->company_name }} flyer" class="h-56 w-full object-cover">
            @endif

            <div class="p-6">
                <p class="text-sm font-medium" style="color: var(--brand)">Access provisioned</p>
                <h1 class="mt-2 text-2xl font-semibold">{{ $package->name }} is ready for this device.</h1>
                @if ($tenant)
                    <p class="mt-2 text-sm text-zinc-600">{{ $tenant->company_name }} hotspot access has been added for this device.</p>
                @endif
                <dl class="mt-5 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Device</dt>
                        <dd class="font-mono font-medium">{{ $macAddress }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Expires</dt>
                        <dd class="font-medium">{{ $subscription->expires_at->toDayDateTimeString() }}</dd>
                    </div>
                </dl>

                @if ($loginUrl)
                    <div class="mt-6 rounded-md bg-zinc-100 p-4 text-sm text-zinc-700">
                        <div class="flex items-center gap-3">
                            <span class="h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-zinc-300 border-t-zinc-900"></span>
                            <div>
                                <p class="font-medium text-zinc-950">Connecting this device...</p>
                                <p class="mt-1 text-xs text-zinc-500">You will be returned to the hotspot gateway automatically.</p>
                            </div>
                        </div>
                    </div>

                    <form id="mikrotik-login" method="POST" action="{{ $loginUrl }}" class="mt-4">
                        <input type="hidden" name="username" value="{{ $username }}">
                        <input type="hidden" name="password" value="{{ $password }}">
                        @if ($originalUrl)
                            <input type="hidden" name="dst" value="{{ $originalUrl }}">
                        @endif
                        <button class="w-full rounded-md px-4 py-2 text-sm font-medium text-white" style="background-color: var(--brand)">
                            Connect now
                        </button>
                    </form>

                    <script>
                        window.setTimeout(() => document.getElementById('mikrotik-login')?.submit(), 1800);
                    </script>
                @else
                    <div class="mt-6 rounded-md bg-zinc-100 p-4 text-sm text-zinc-700">
                        Access has been added in RADIUS. Reopen a website from this phone to let MikroTik authenticate the device.
                    </div>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
