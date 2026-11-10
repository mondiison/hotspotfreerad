<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Renewed - {{ $subscriber->shop->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $tenant = $subscriber->shop->tenant;
    $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $tenant->brand_color ?? '#10b981' }}">
    <main class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <div class="flex items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-cover">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                @endif
                <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
            </div>

            <p class="mt-4 text-sm font-medium" style="color: var(--brand)">Payment received</p>
            <h1 class="mt-2 text-2xl font-semibold">Your plan has been renewed.</h1>
            <p class="mt-2 text-sm text-zinc-600">No further action is needed -- your connection is already active.</p>

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Account</dt>
                    <dd class="font-mono font-medium">{{ $subscriber->username }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Plan</dt>
                    <dd class="font-medium">{{ $subscriber->package?->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">New expiry</dt>
                    <dd class="font-medium">{{ $subscriber->expires_at?->format('M j, Y g:i A') }}</dd>
                </div>
            </dl>
        </section>
    </main>
</body>
</html>
