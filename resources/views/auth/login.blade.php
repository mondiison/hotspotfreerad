<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - HotspotFreeRAD</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased">
    <main class="grid min-h-screen lg:grid-cols-[1.05fr_0.95fr]">
        <section class="hidden bg-zinc-950 text-white lg:flex lg:flex-col lg:justify-between lg:px-12 lg:py-10 xl:px-16">
            <div>
                <p class="text-lg font-semibold">HotspotFreeRAD</p>
                <p class="mt-2 text-sm text-zinc-400">FreeRADIUS control for MikroTik hotspot operators.</p>
            </div>

            <div class="max-w-xl">
                <p class="text-sm font-medium uppercase tracking-wide text-emerald-300">SaaS Operations</p>
                <h1 class="mt-4 text-5xl font-semibold leading-tight">Run tenants, routers, plans, and access from one console.</h1>
                <p class="mt-5 text-base leading-7 text-zinc-300">
                    Built for hotspot businesses that need clean tenant separation, branded public pages, and RADIUS-backed customer access.
                </p>
            </div>

            <div class="grid grid-cols-3 gap-3 text-sm">
                <div class="rounded-lg border border-white/10 p-4">
                    <p class="text-2xl font-semibold">01</p>
                    <p class="mt-2 text-zinc-400">Tenant scope</p>
                </div>
                <div class="rounded-lg border border-white/10 p-4">
                    <p class="text-2xl font-semibold">02</p>
                    <p class="mt-2 text-zinc-400">Router health</p>
                </div>
                <div class="rounded-lg border border-white/10 p-4">
                    <p class="text-2xl font-semibold">03</p>
                    <p class="mt-2 text-zinc-400">Captive portal</p>
                </div>
            </div>
        </section>

        <section class="flex min-h-screen items-center justify-center px-5 py-10 sm:px-8">
            <div class="w-full max-w-md">
                <div class="mb-8 lg:hidden">
                    <p class="text-sm font-medium text-emerald-700">HotspotFreeRAD</p>
                    <h1 class="mt-2 text-3xl font-semibold">Sign in</h1>
                    <p class="mt-2 text-sm text-zinc-600">Manage tenants, routers, packages, and captive portal access.</p>
                </div>

                <div class="hidden lg:mb-8 lg:block">
                    <p class="text-sm font-medium text-emerald-700">Welcome back</p>
                    <h2 class="mt-2 text-3xl font-semibold">Sign in to your console</h2>
                    <p class="mt-2 text-sm text-zinc-600">Use your super admin or tenant admin credentials.</p>
                </div>

                <form method="POST" action="{{ route('login.store') }}" class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
                    @csrf

                    <div class="space-y-5">
                        <label class="block">
                            <span class="text-sm font-medium">Email</span>
                            <input type="email" name="email" value="{{ old('email') }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required autofocus>
                            @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="flex items-center justify-between gap-3 text-sm font-medium">
                                Password
                                <a href="{{ route('password.request') }}" class="text-xs font-semibold text-emerald-700 hover:text-emerald-800">Forgot password?</a>
                            </span>
                            <input type="password" name="password" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                            @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="flex items-center gap-2 text-sm text-zinc-700">
                            <input type="checkbox" name="remember" value="1" class="rounded border-zinc-300">
                            Remember this device
                        </label>
                    </div>

                    <button class="mt-6 w-full rounded-md bg-zinc-950 px-4 py-2.5 text-sm font-medium text-white">Sign In</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
