@props(['title' => 'Client'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - {{ auth()->user()->client?->tenant?->company_name ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
    <header class="border-b border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div>
                <p class="font-semibold">{{ auth()->user()->client?->name }}</p>
                <p class="text-xs text-zinc-500">{{ auth()->user()->client?->tenant?->company_name }}</p>
            </div>
            <nav class="flex flex-wrap items-center gap-4 text-sm">
                <a href="{{ route('client.dashboard') }}" class="hover:underline">Dashboard</a>
                <a href="{{ route('client.packages.index') }}" class="hover:underline">Packages</a>
                <a href="{{ route('client.vouchers.index') }}" class="hover:underline">Vouchers</a>
                <a href="{{ route('client.reports.index') }}" class="hover:underline">Reports</a>
                <a href="{{ route('client.branding.index') }}" class="hover:underline">Branding</a>
                <a href="{{ route('client.billing.index') }}" class="hover:underline">Billing</a>
                <a href="{{ route('client.support.index') }}" class="hover:underline">Support</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-zinc-500 hover:underline">Sign out</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-5 py-8">
        @if (session('status'))
            <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
