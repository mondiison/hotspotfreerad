<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - HotspotFreeRAD</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased">
    <main class="flex min-h-screen items-center justify-center px-5 py-10">
        <div class="w-full max-w-md">
            <div class="mb-8">
                <p class="text-sm font-medium text-emerald-700">HotspotFreeRAD</p>
                <h1 class="mt-2 text-3xl font-semibold">Reset your password</h1>
                <p class="mt-2 text-sm leading-6 text-zinc-600">Enter your admin email and we will send a secure reset link if the account exists.</p>
            </div>

            @if (session('status'))
                <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
                @csrf

                <label class="block">
                    <span class="text-sm font-medium">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required autofocus>
                    @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <button class="mt-6 w-full rounded-md bg-zinc-950 px-4 py-2.5 text-sm font-medium text-white">Send reset link</button>
            </form>

            <a href="{{ route('login') }}" class="mt-5 inline-flex text-sm font-medium text-zinc-700 hover:text-zinc-950">Back to sign in</a>
        </div>
    </main>
</body>
</html>
