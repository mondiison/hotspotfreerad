<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - HotspotFreeRAD</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-5 py-10">
        <div class="mb-8">
            <p class="text-sm font-medium text-emerald-300">HotspotFreeRAD</p>
            <h1 class="mt-2 text-3xl font-semibold">Sign in</h1>
            <p class="mt-2 text-sm text-zinc-300">Manage tenants, routers, packages, and captive portal access.</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            @csrf

            <div class="space-y-5">
                <label class="block">
                    <span class="text-sm font-medium">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required autofocus>
                    @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Password</span>
                    <input type="password" name="password" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="remember" value="1" class="rounded border-zinc-300">
                    Remember this device
                </label>
            </div>

            <button class="mt-6 w-full rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Sign In</button>
        </form>
    </main>
</body>
</html>
