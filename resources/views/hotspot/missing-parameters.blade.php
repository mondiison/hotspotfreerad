<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hotspot Redirect Needs Router Data</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <p class="text-sm font-medium text-amber-600">Redirect incomplete</p>
            <h1 class="mt-2 text-2xl font-semibold">The portal opened, but MikroTik did not send the device details.</h1>

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Received NAS ID</dt>
                    <dd class="font-mono font-medium">{{ $nasIdentifier ?: 'Missing' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Device MAC</dt>
                    <dd class="font-mono font-medium">{{ $macAddress ?: 'Missing' }}</dd>
                </div>
            </dl>

            <div class="mt-5 rounded-md bg-zinc-100 p-4 text-sm leading-6 text-zinc-700">
                Replace the MikroTik hotspot <span class="font-mono">login.html</span> with the latest template from HotspotFreeRAD docs. The template must pass <span class="font-mono">$(mac)</span> and <span class="font-mono">$(identity)</span> to this portal URL.
            </div>
        </section>
    </main>
</body>
</html>
