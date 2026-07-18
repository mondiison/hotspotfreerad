<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password - HotspotFreeRAD</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased">
    <main class="flex min-h-screen items-center justify-center px-5 py-10">
        <section class="w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <div>
                <p class="text-sm font-medium text-emerald-700">Required update</p>
                <h1 class="mt-2 text-2xl font-semibold">Change your temporary password</h1>
                <p class="mt-2 text-sm leading-6 text-zinc-600">Before opening the workspace, set a private password that only you know.</p>
            </div>

            <form method="POST" action="{{ route('password.force-update') }}" class="mt-6 space-y-5">
                @csrf
                @method('PUT')

                <label class="block">
                    <span class="text-sm font-medium">Temporary password</span>
                    <input type="password" name="current_password" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required autofocus>
                    @error('current_password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">New password</span>
                    <input type="password" name="password" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Confirm new password</span>
                    <input type="password" name="password_confirmation" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                </label>

                <button class="w-full rounded-md bg-zinc-950 px-4 py-2.5 text-sm font-medium text-white">Update password</button>
            </form>
        </section>
    </main>
</body>
</html>
