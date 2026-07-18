<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose New Password - HotspotFreeRAD</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased">
    <main class="flex min-h-screen items-center justify-center px-5 py-10">
        <section class="grid w-full max-w-5xl overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm lg:grid-cols-[0.9fr_1.1fr]">
            <div class="hidden bg-zinc-950 p-10 text-white lg:flex lg:flex-col lg:justify-between">
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-lg bg-emerald-700 text-sm font-semibold">HF</span>
                    <div>
                        <p class="font-semibold">HotspotFreeRAD</p>
                        <p class="mt-1 text-xs text-zinc-400">Password update</p>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-300">Account security</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-tight">Set a new password for your admin workspace.</h1>
                    <p class="mt-5 text-sm leading-6 text-zinc-300">After the reset succeeds, sign in again with the updated credentials.</p>
                </div>

                <p class="text-xs text-zinc-500">Use a unique password for production admin accounts.</p>
            </div>

            <div class="flex items-center justify-center px-5 py-10 sm:px-8 lg:px-12">
                <div class="w-full max-w-md">
                    <div class="mb-8">
                        <p class="text-sm font-medium text-emerald-700">HotspotFreeRAD</p>
                        <h1 class="mt-2 text-3xl font-semibold">Choose a new password</h1>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">Use at least 8 characters.</p>
                    </div>

                    <form method="POST" action="{{ route('password.update') }}" class="rounded-lg border border-zinc-200 bg-zinc-50 p-6">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">

                        <div class="space-y-5">
                            <label class="block">
                                <span class="text-sm font-medium">Email</span>
                                <input type="email" name="email" value="{{ old('email', $email) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required autofocus>
                                @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium">New password</span>
                                <input type="password" name="password" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                                @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium">Confirm password</span>
                                <input type="password" name="password_confirmation" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                            </label>
                        </div>

                        <button class="mt-6 w-full rounded-md bg-zinc-950 px-4 py-2.5 text-sm font-medium text-white">Update password</button>
                    </form>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
