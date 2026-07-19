<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-Factor Challenge - HotspotFreeRAD</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased" style="--brand: #047857">
    <main class="flex min-h-screen items-center justify-center px-5 py-10">
        <section class="w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <div>
                <p class="text-sm font-medium" style="color: var(--brand)">Secure sign in</p>
                <h1 class="mt-2 text-2xl font-semibold">Enter your 2FA code</h1>
                <p class="mt-2 text-sm leading-6 text-zinc-600">Open your authenticator app and enter the current 6-digit code. You can use a recovery code if your device is unavailable.</p>
            </div>

            <form method="POST" action="{{ route('two-factor.login') }}" class="mt-6 space-y-5">
                @csrf

                <label class="block">
                    <span class="text-sm font-medium">Authenticator code</span>
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" autofocus>
                    @error('code') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Recovery code</span>
                    <input type="text" name="recovery_code" autocomplete="one-time-code" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                    @error('recovery_code') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <button class="w-full rounded-md px-4 py-2.5 text-sm font-medium text-white" style="background-color: var(--brand)">Verify and continue</button>
            </form>
        </section>
    </main>
</body>
</html>
