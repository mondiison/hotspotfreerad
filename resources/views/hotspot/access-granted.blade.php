<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Granted</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <p class="text-sm font-medium text-emerald-600">Access provisioned</p>
            <h1 class="mt-2 text-2xl font-semibold">{{ $package->name }} is ready for this device.</h1>
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
                <form id="mikrotik-login" method="POST" action="{{ $loginUrl }}" class="mt-6">
                    <input type="hidden" name="username" value="{{ $username }}">
                    <input type="hidden" name="password" value="{{ $password }}">
                    @if ($originalUrl)
                        <input type="hidden" name="dst" value="{{ $originalUrl }}">
                    @endif
                    <button class="w-full rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">
                        Continue
                    </button>
                </form>

                <script>
                    window.setTimeout(() => document.getElementById('mikrotik-login').submit(), 700);
                </script>
            @else
                <div class="mt-6 rounded-md bg-zinc-100 p-4 text-sm text-zinc-700">
                    Access has been added in RADIUS. Reopen a website from this phone to let MikroTik authenticate the device.
                </div>
            @endif
        </section>
    </main>
</body>
</html>
