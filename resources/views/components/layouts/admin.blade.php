<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'HotspotFreeRAD' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body
    class="min-h-screen bg-zinc-100 text-zinc-950 antialiased"
    x-data="{
        mobileSidebarOpen: false,
        sidebarCollapsed: localStorage.getItem('adminSidebarCollapsed') === '1',
        setSidebarCollapsed(value) {
            this.sidebarCollapsed = value;
            localStorage.setItem('adminSidebarCollapsed', value ? '1' : '0');
        },
        accountMenuOpen: false,
        headerAccountMenuOpen: false,
    }"
>
    <div class="min-h-screen lg:flex">
        <div
            x-cloak
            x-show="mobileSidebarOpen"
            x-transition.opacity
            @click="mobileSidebarOpen = false"
            class="fixed inset-0 z-30 bg-zinc-950/40 lg:hidden"
        ></div>

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-72 flex-col border-r border-zinc-200 bg-white px-5 py-6 transition-all duration-200 lg:static lg:translate-x-0"
            :class="{
                '-translate-x-full': ! mobileSidebarOpen,
                'translate-x-0': mobileSidebarOpen,
                'lg:w-20 lg:px-3': sidebarCollapsed,
                'lg:w-64 lg:px-5': ! sidebarCollapsed,
            }"
        >
            <div class="flex items-start justify-between gap-4">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-3" title="HotspotFreeRAD">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-zinc-950 text-sm font-semibold text-white">HF</span>
                    <span class="min-w-0" :class="{ 'lg:hidden': sidebarCollapsed }">
                        <span class="block truncate text-lg font-semibold">HotspotFreeRAD</span>
                        <span class="mt-1 block truncate text-sm text-zinc-500">FreeRADIUS control</span>
                    </span>
                </a>

                <button type="button" @click="mobileSidebarOpen = false" class="rounded-md border border-zinc-200 p-2 text-zinc-600 hover:bg-zinc-100 lg:hidden" aria-label="Close navigation">
                    <span aria-hidden="true">&times;</span>
                </button>

                <button
                    type="button"
                    @click="setSidebarCollapsed(! sidebarCollapsed)"
                    class="hidden rounded-md border border-zinc-200 p-2 text-zinc-600 hover:bg-zinc-100 lg:block"
                    :class="{ 'lg:mx-auto': sidebarCollapsed }"
                    aria-label="Collapse navigation"
                >
                    <flux:icon.chevron-left x-show="! sidebarCollapsed" class="size-4" />
                    <flux:icon.chevron-right x-show="sidebarCollapsed" class="size-4" />
                </button>
            </div>

            <nav class="mt-8 space-y-1 text-sm">
                @php
                    $links = [
                        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'squares-2x2'],
                        ['label' => 'Tenants', 'route' => 'admin.tenants.index', 'icon' => 'building-storefront', 'super_admin' => true],
                        ['label' => 'Security', 'route' => 'admin.security.index', 'icon' => 'shield-check', 'super_admin' => true],
                        ['label' => 'Activity', 'route' => 'admin.security-activity.index', 'icon' => 'clock'],
                        ['label' => 'Brand', 'route' => 'admin.brand.edit', 'icon' => 'swatch', 'tenant_admin' => true],
                        ['label' => 'Billing', 'route' => 'admin.billing.index', 'icon' => 'credit-card'],
                        ['label' => 'Users', 'route' => 'admin.users.index', 'icon' => 'users'],
                        ['label' => 'Shops', 'route' => 'admin.shops.index', 'icon' => 'building-storefront'],
                        ['label' => 'Routers', 'route' => 'admin.routers.index', 'icon' => 'signal'],
                        ['label' => 'Packages', 'route' => 'admin.packages.index', 'icon' => 'radio'],
                        ['label' => 'Access', 'route' => 'admin.subscriptions.index', 'icon' => 'key'],
                        ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'banknotes'],
                        ['label' => 'Expenses', 'route' => 'admin.expenses.index', 'icon' => 'receipt-percent'],
                        ['label' => 'Reports', 'route' => 'admin.reports.sales', 'icon' => 'chart-bar'],
                        ['label' => 'Payment Setup', 'route' => 'admin.payment-settings.index', 'icon' => 'credit-card', 'tenant_admin' => true],
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
                        wire:navigate
                        title="{{ $link['label'] }}"
                        class="flex items-center gap-3 rounded-md px-3 py-2 {{ request()->routeIs($sectionPattern) ? 'bg-zinc-950 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                        :class="{ 'lg:justify-center': sidebarCollapsed }"
                    >
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md {{ request()->routeIs($sectionPattern) ? 'bg-white/10' : 'bg-zinc-100' }}">
                            <x-dynamic-component :component="'flux::icon.'.$link['icon']" class="size-4" />
                        </span>
                        <span :class="{ 'lg:hidden': sidebarCollapsed }">{{ $link['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            @auth
                <div class="relative mt-auto pt-8" @keydown.escape.window="accountMenuOpen = false">
                    <div
                        x-cloak
                        x-show="accountMenuOpen"
                        x-transition.origin.bottom.left
                        @click.outside="accountMenuOpen = false"
                        class="absolute bottom-full left-0 z-50 mb-3 w-full min-w-60 rounded-lg border border-zinc-200 bg-white p-2 text-sm shadow-lg"
                    >
                        <a href="{{ route('admin.profile.edit') }}" wire:navigate class="flex items-center gap-3 rounded-md px-3 py-2 text-zinc-700 hover:bg-zinc-100">
                            <flux:icon.user-circle class="size-4" />
                            <span>Profile</span>
                        </a>

                        <a href="{{ route('admin.passkeys.index') }}" wire:navigate class="flex items-center gap-3 rounded-md px-3 py-2 text-zinc-700 hover:bg-zinc-100">
                            <flux:icon.key class="size-4" />
                            <span>Passkeys</span>
                        </a>

                        @if (auth()->user()->isTenantAdmin() && auth()->user()->tenant)
                            <a href="{{ auth()->user()->tenant->publicUrl() }}" target="_blank" class="flex items-center gap-3 rounded-md px-3 py-2 text-zinc-700 hover:bg-zinc-100">
                                <flux:icon.arrow-top-right-on-square class="size-4" />
                                <span>Public page</span>
                            </a>
                        @endif

                        <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-zinc-100 pt-1">
                            @csrf
                            <button class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-red-700 hover:bg-red-50">
                                <flux:icon.arrow-left-start-on-rectangle class="size-4" />
                                <span>Sign out</span>
                            </button>
                        </form>
                    </div>

                    <button
                        type="button"
                        @click="accountMenuOpen = ! accountMenuOpen"
                        class="flex w-full items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-left text-sm hover:bg-zinc-100"
                        :class="{ 'lg:justify-center lg:p-2': sidebarCollapsed }"
                        title="{{ auth()->user()->name }}"
                    >
                        <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-md bg-zinc-950 text-xs font-semibold text-white">
                            @if (auth()->user()->avatarUrl())
                                <img src="{{ auth()->user()->avatarUrl() }}" alt="{{ auth()->user()->name }} profile photo" class="h-full w-full object-cover">
                            @else
                                {{ auth()->user()->initials() }}
                            @endif
                        </span>
                        <span class="min-w-0 flex-1" :class="{ 'lg:hidden': sidebarCollapsed }">
                            <span class="block truncate font-medium">{{ auth()->user()->name }}</span>
                            <span class="mt-1 block truncate text-xs text-zinc-500">{{ str_replace('_', ' ', auth()->user()->role) }}</span>
                        </span>
                        <flux:icon.chevron-up class="size-4 text-zinc-500" x-bind:class="{ 'lg:hidden': sidebarCollapsed }" />
                    </button>
                </div>
            @endauth
        </aside>

        <main class="min-w-0 flex-1">
            <header class="border-b border-zinc-200 bg-white px-5 py-5 lg:px-8">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button" @click="mobileSidebarOpen = true" class="rounded-md border border-zinc-200 p-2 text-zinc-600 hover:bg-zinc-100 lg:hidden" aria-label="Open navigation">
                            <span aria-hidden="true">&#9776;</span>
                        </button>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="truncate text-xl font-semibold">{{ $heading ?? $title ?? 'Dashboard' }}</h1>
                                @auth
                                    <flux:badge :color="auth()->user()->isSuperAdmin() ? 'blue' : 'green'">
                                        {{ auth()->user()->isSuperAdmin() ? 'Platform Admin' : 'Tenant Admin' }}
                                    </flux:badge>
                                @endauth
                            </div>
                            @isset($subheading)
                                <p class="mt-1 text-sm text-zinc-500">{{ $subheading }}</p>
                            @endisset
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @auth
                            <div class="relative" @keydown.escape.window="headerAccountMenuOpen = false">
                                <button
                                    type="button"
                                    @click="headerAccountMenuOpen = ! headerAccountMenuOpen"
                                    class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white p-2 text-sm hover:bg-zinc-50"
                                    title="{{ auth()->user()->name }}"
                                >
                                    <span class="grid h-8 w-8 place-items-center overflow-hidden rounded-md bg-zinc-950 text-xs font-semibold text-white">
                                        @if (auth()->user()->avatarUrl())
                                            <img src="{{ auth()->user()->avatarUrl() }}" alt="{{ auth()->user()->name }} profile photo" class="h-full w-full object-cover">
                                        @else
                                            {{ auth()->user()->initials() }}
                                        @endif
                                    </span>
                                    <span class="hidden max-w-36 truncate font-medium xl:block">{{ auth()->user()->name }}</span>
                                    <flux:icon.chevron-down class="size-4 text-zinc-500" />
                                </button>

                                <div
                                    x-cloak
                                    x-show="headerAccountMenuOpen"
                                    x-transition.origin.top.right
                                    @click.outside="headerAccountMenuOpen = false"
                                    class="absolute right-0 z-50 mt-3 w-64 rounded-lg border border-zinc-200 bg-white p-2 text-sm shadow-lg"
                                >
                                    <div class="px-3 py-2">
                                        <p class="truncate font-medium">{{ auth()->user()->name }}</p>
                                        <p class="mt-1 truncate text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                                    </div>

                                    <a href="{{ route('admin.profile.edit') }}" wire:navigate class="flex items-center gap-3 rounded-md px-3 py-2 text-zinc-700 hover:bg-zinc-100">
                                        <flux:icon.user-circle class="size-4" />
                                        <span>Profile</span>
                                    </a>

                                    <a href="{{ route('admin.passkeys.index') }}" wire:navigate class="flex items-center gap-3 rounded-md px-3 py-2 text-zinc-700 hover:bg-zinc-100">
                                        <flux:icon.key class="size-4" />
                                        <span>Passkeys</span>
                                    </a>

                                    @if (auth()->user()->isTenantAdmin() && auth()->user()->tenant)
                                        <a href="{{ auth()->user()->tenant->publicUrl() }}" target="_blank" class="flex items-center gap-3 rounded-md px-3 py-2 text-zinc-700 hover:bg-zinc-100">
                                            <flux:icon.arrow-top-right-on-square class="size-4" />
                                            <span>Public page</span>
                                        </a>
                                    @endif

                                    <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-zinc-100 pt-1">
                                        @csrf
                                        <button class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-red-700 hover:bg-red-50">
                                            <flux:icon.arrow-left-start-on-rectangle class="size-4" />
                                            <span>Sign out</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endauth

                        @isset($action)
                            {{ $action }}
                        @endisset
                    </div>
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
</body>
</html>
