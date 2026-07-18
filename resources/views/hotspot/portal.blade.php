<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shop->name }} Hotspot</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-5 py-8">
        <header class="flex items-start justify-between gap-4 border-b border-white/10 pb-5">
            <div>
                <p class="text-sm text-emerald-300">{{ $shop->tenant->company_name }}</p>
                <h1 class="mt-1 text-3xl font-semibold">{{ $shop->name }}</h1>
                <p class="mt-2 text-sm text-zinc-300">Device {{ $macAddress }}</p>
            </div>
            <div class="rounded-md border border-white/10 px-3 py-2 text-right text-xs text-zinc-300">
                <p>{{ $router->nas_identifier }}</p>
                <p>{{ $router->wireguard_internal_ip }}</p>
            </div>
        </header>

        <section class="py-8">
            <h2 class="text-lg font-semibold">Choose internet access</h2>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                @forelse ($packages as $package)
                    <article class="rounded-lg border border-white/10 bg-white p-5 text-zinc-950">
                        <h3 class="text-lg font-semibold">{{ $package->name }}</h3>
                        <p class="mt-3 text-3xl font-semibold">{{ $package->currency }} {{ number_format($package->price, 2) }}</p>
                        <dl class="mt-4 space-y-2 text-sm text-zinc-600">
                            <div class="flex justify-between gap-4">
                                <dt>Speed</dt>
                                <dd class="font-medium text-zinc-950">{{ $package->speed_limit_profile }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Time</dt>
                                <dd class="font-medium text-zinc-950">{{ gmdate('H:i', $package->limit_uptime_seconds) }}</dd>
                            </div>
                        </dl>
                        <button class="mt-5 w-full rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white" disabled>
                            Payment coming next
                        </button>
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
