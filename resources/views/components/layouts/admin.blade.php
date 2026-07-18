<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'HotspotFreeRAD' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased">
    <div class="flex min-h-screen">
        <aside class="w-64 border-r border-zinc-200 bg-white px-5 py-6">
            <a href="{{ route('admin.dashboard') }}" class="block text-lg font-semibold">HotspotFreeRAD</a>
            <p class="mt-1 text-sm text-zinc-500">FreeRADIUS control</p>

            @auth
                <div class="mt-6 rounded-md bg-zinc-100 p-3 text-sm">
                    <p class="font-medium">{{ auth()->user()->name }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ str_replace('_', ' ', auth()->user()->role) }}</p>
                </div>
            @endauth

            <nav class="mt-8 space-y-1 text-sm">
                @php
                    $links = [
                        ['label' => 'Dashboard', 'route' => 'admin.dashboard'],
                        ['label' => 'Tenants', 'route' => 'admin.tenants.index', 'super_admin' => true],
                        ['label' => 'Shops', 'route' => 'admin.shops.index'],
                        ['label' => 'Routers', 'route' => 'admin.routers.index'],
                        ['label' => 'Packages', 'route' => 'admin.packages.index'],
                    ];
                @endphp

                @foreach ($links as $link)
                    @continue(($link['super_admin'] ?? false) && ! auth()->user()?->isSuperAdmin())

                    @php
                        $sectionPattern = $link['route'] === 'admin.dashboard'
                            ? $link['route']
                            : \Illuminate\Support\Str::beforeLast($link['route'], '.') . '.*';
                    @endphp
                    <a
                        href="{{ route($link['route']) }}"
                        class="block rounded-md px-3 py-2 {{ request()->routeIs($sectionPattern) ? 'bg-zinc-950 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                    >
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('logout') }}" class="mt-8">
                @csrf
                <button class="w-full rounded-md border border-zinc-200 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100">
                    Sign out
                </button>
            </form>
        </aside>

        <main class="flex-1">
            <header class="border-b border-zinc-200 bg-white px-8 py-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-semibold">{{ $heading ?? $title ?? 'Dashboard' }}</h1>
                        @isset($subheading)
                            <p class="mt-1 text-sm text-zinc-500">{{ $subheading }}</p>
                        @endisset
                    </div>

                    @isset($action)
                        <div>{{ $action }}</div>
                    @endisset
                </div>
            </header>

            <div class="px-8 py-6">
                @if (session('status'))
                    <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <p class="font-medium">Please fix the highlighted fields.</p>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
