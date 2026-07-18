<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unknown Hotspot Router</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <p class="text-sm font-medium text-red-600">Router not registered</p>
            <h1 class="mt-2 text-2xl font-semibold">This hotspot is reaching the portal, but the NAS ID is unknown.</h1>
            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Received NAS ID</dt>
                    <dd class="font-mono font-medium">{{ $nasIdentifier }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Device MAC</dt>
                    <dd class="font-mono font-medium">{{ $macAddress }}</dd>
                </div>
            </dl>
            <div class="mt-5 rounded-md bg-zinc-100 p-4 text-sm text-zinc-700">
                Add or update a router in HotspotFreeRAD so its NAS identifier exactly matches the MikroTik system identity.
            </div>
        </section>
    </main>
</body>
</html>
