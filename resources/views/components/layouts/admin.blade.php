<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'HotspotFreeRAD' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased">
    <div class="min-h-screen lg:flex">
        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-zinc-950/40 lg:hidden"></div>

        <aside id="adminSidebar" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-zinc-200 bg-white px-5 py-6 transition-all duration-200 lg:static lg:w-64 lg:translate-x-0">
            <div class="flex items-start justify-between gap-4">
                <a href="{{ route('admin.dashboard') }}" class="flex min-w-0 items-center gap-3" title="HotspotFreeRAD">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-zinc-950 text-sm font-semibold text-white">HF</span>
                    <span data-sidebar-label class="min-w-0">
                        <span class="block truncate text-lg font-semibold">HotspotFreeRAD</span>
                        <span class="mt-1 block truncate text-sm text-zinc-500">FreeRADIUS control</span>
                    </span>
                </a>

                <button type="button" id="sidebarClose" class="rounded-md border border-zinc-200 p-2 text-zinc-600 hover:bg-zinc-100 lg:hidden" aria-label="Close navigation">
                    <span aria-hidden="true">&times;</span>
                </button>

                <button type="button" id="sidebarCollapse" class="hidden rounded-md border border-zinc-200 p-2 text-zinc-600 hover:bg-zinc-100 lg:block" aria-label="Collapse navigation">
                    <flux:icon.chevron-left data-collapse-icon-left class="size-4" />
                    <flux:icon.chevron-right data-collapse-icon-right class="hidden size-4" />
                </button>
            </div>

            @auth
                <div class="mt-6 flex items-center gap-3 rounded-md bg-zinc-100 p-3 text-sm" data-sidebar-user title="{{ auth()->user()->name }}">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-white text-xs font-semibold text-zinc-700">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span>
                    <div data-sidebar-label class="min-w-0">
                        <p class="truncate font-medium">{{ auth()->user()->name }}</p>
                        <p class="mt-1 truncate text-xs text-zinc-500">{{ str_replace('_', ' ', auth()->user()->role) }}</p>
                    </div>
                </div>
            @endauth

            <nav class="mt-8 space-y-1 text-sm">
                @php
                    $links = [
                        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'squares-2x2'],
                        ['label' => 'Tenants', 'route' => 'admin.tenants.index', 'icon' => 'building-storefront', 'super_admin' => true],
                        ['label' => 'Brand', 'route' => 'admin.brand.edit', 'icon' => 'swatch', 'tenant_admin' => true],
                        ['label' => 'Billing', 'route' => 'admin.billing.index', 'icon' => 'credit-card'],
                        ['label' => 'Shops', 'route' => 'admin.shops.index', 'icon' => 'building-storefront'],
                        ['label' => 'Routers', 'route' => 'admin.routers.index', 'icon' => 'signal'],
                        ['label' => 'Packages', 'route' => 'admin.packages.index', 'icon' => 'radio'],
                    ];
                @endphp

                @foreach ($links as $link)
                    @continue(($link['super_admin'] ?? false) && ! auth()->user()?->isSuperAdmin())
                    @continue(($link['tenant_admin'] ?? false) && auth()->user()?->isSuperAdmin())

                    @php
                        $sectionPattern = $link['route'] === 'admin.dashboard'
                            ? $link['route']
                            : \Illuminate\Support\Str::beforeLast($link['route'], '.') . '.*';
                    @endphp
                    <a
                        href="{{ route($link['route']) }}"
                        title="{{ $link['label'] }}"
                        class="flex items-center gap-3 rounded-md px-3 py-2 {{ request()->routeIs($sectionPattern) ? 'bg-zinc-950 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                        data-sidebar-nav-item
                    >
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md {{ request()->routeIs($sectionPattern) ? 'bg-white/10' : 'bg-zinc-100' }}">
                            <x-dynamic-component :component="'flux::icon.'.$link['icon']" class="size-4" />
                        </span>
                        <span data-sidebar-label>{{ $link['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('logout') }}" class="mt-auto pt-8">
                @csrf
                <button class="flex w-full items-center gap-3 rounded-md border border-zinc-200 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100" title="Sign out" data-sidebar-nav-item>
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-zinc-100">
                        <flux:icon.arrow-left-start-on-rectangle class="size-4" />
                    </span>
                    <span data-sidebar-label>Sign out</span>
                </button>
            </form>
        </aside>

        <main class="min-w-0 flex-1">
            <header class="border-b border-zinc-200 bg-white px-5 py-5 lg:px-8">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button" id="sidebarOpen" class="rounded-md border border-zinc-200 p-2 text-zinc-600 hover:bg-zinc-100 lg:hidden" aria-label="Open navigation">
                            <span aria-hidden="true">&#9776;</span>
                        </button>

                        <div class="min-w-0">
                            <h1 class="truncate text-xl font-semibold">{{ $heading ?? $title ?? 'Dashboard' }}</h1>
                            @isset($subheading)
                                <p class="mt-1 text-sm text-zinc-500">{{ $subheading }}</p>
                            @endisset
                        </div>
                    </div>

                    @isset($action)
                        <div class="shrink-0">{{ $action }}</div>
                    @endisset
                </div>
            </header>

            <div class="px-5 py-6 lg:px-8">
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

    @fluxScripts
    <script>
        (() => {
            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const openButton = document.getElementById('sidebarOpen');
            const closeButton = document.getElementById('sidebarClose');
            const collapseButton = document.getElementById('sidebarCollapse');
            const collapseIconLeft = document.querySelector('[data-collapse-icon-left]');
            const collapseIconRight = document.querySelector('[data-collapse-icon-right]');
            const labels = document.querySelectorAll('[data-sidebar-label]');
            const navItems = document.querySelectorAll('[data-sidebar-nav-item]');
            const userCard = document.querySelector('[data-sidebar-user]');

            const openMobile = () => {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            };

            const closeMobile = () => {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            };

            const setCollapsed = (collapsed) => {
                sidebar.classList.toggle('lg:w-20', collapsed);
                sidebar.classList.toggle('lg:w-64', ! collapsed);
                sidebar.classList.toggle('lg:px-3', collapsed);
                sidebar.classList.toggle('lg:px-5', ! collapsed);
                labels.forEach((label) => label.classList.toggle('lg:hidden', collapsed));
                navItems.forEach((item) => item.classList.toggle('lg:justify-center', collapsed));
                userCard?.classList.toggle('lg:justify-center', collapsed);
                collapseButton?.classList.toggle('lg:mx-auto', collapsed);
                collapseIconLeft?.classList.toggle('hidden', collapsed);
                collapseIconRight?.classList.toggle('hidden', ! collapsed);
                localStorage.setItem('adminSidebarCollapsed', collapsed ? '1' : '0');
            };

            openButton?.addEventListener('click', openMobile);
            closeButton?.addEventListener('click', closeMobile);
            overlay?.addEventListener('click', closeMobile);
            collapseButton?.addEventListener('click', () => setCollapsed(! sidebar.classList.contains('lg:w-20')));

            setCollapsed(localStorage.getItem('adminSidebarCollapsed') === '1');
        })();
    </script>
</body>
</html>
