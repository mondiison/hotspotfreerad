<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
                <a href="{{ route('admin.dashboard') }}" class="flex min-w-0 items-center gap-3" title="HotspotFreeRAD">
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

            @auth
                <a href="{{ route('admin.profile.edit') }}" class="mt-6 flex items-center gap-3 rounded-md bg-zinc-100 p-3 text-sm hover:bg-zinc-200" :class="{ 'lg:justify-center': sidebarCollapsed }" title="{{ auth()->user()->name }}">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-white text-xs font-semibold text-zinc-700">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span>
                    <div class="min-w-0" :class="{ 'lg:hidden': sidebarCollapsed }">
                        <p class="truncate font-medium">{{ auth()->user()->name }}</p>
                        <p class="mt-1 truncate text-xs text-zinc-500">{{ str_replace('_', ' ', auth()->user()->role) }}</p>
                    </div>
                </a>
            @endauth

            <nav class="mt-8 space-y-1 text-sm">
                @php
                    $links = [
                        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'squares-2x2'],
                        ['label' => 'My Profile', 'route' => 'admin.profile.edit', 'icon' => 'user-circle'],
                        ['label' => 'Tenants', 'route' => 'admin.tenants.index', 'icon' => 'building-storefront', 'super_admin' => true],
                        ['label' => 'Brand', 'route' => 'admin.brand.edit', 'icon' => 'swatch', 'tenant_admin' => true],
                        ['label' => 'Billing', 'route' => 'admin.billing.index', 'icon' => 'credit-card'],
                        ['label' => 'Users', 'route' => 'admin.users.index', 'icon' => 'users'],
                        ['label' => 'Shops', 'route' => 'admin.shops.index', 'icon' => 'building-storefront'],
                        ['label' => 'Routers', 'route' => 'admin.routers.index', 'icon' => 'signal'],
                        ['label' => 'Packages', 'route' => 'admin.packages.index', 'icon' => 'radio'],
                        ['label' => 'Access', 'route' => 'admin.subscriptions.index', 'icon' => 'key'],
                        ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'banknotes'],
                        ['label' => 'Expenses', 'route' => 'admin.expenses.index', 'icon' => 'receipt-percent'],
                        ['label' => 'Expense Categories', 'route' => 'admin.expense-categories.index', 'icon' => 'tag'],
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

            <form method="POST" action="{{ route('logout') }}" class="mt-auto pt-8">
                @csrf
                <button class="flex w-full items-center gap-3 rounded-md border border-zinc-200 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100" :class="{ 'lg:justify-center': sidebarCollapsed }" title="Sign out">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-zinc-100">
                        <flux:icon.arrow-left-start-on-rectangle class="size-4" />
                    </span>
                    <span :class="{ 'lg:hidden': sidebarCollapsed }">Sign out</span>
                </button>
            </form>
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
                            @if (auth()->user()->isTenantAdmin() && auth()->user()->tenant)
                                <flux:button href="{{ auth()->user()->tenant->publicUrl() }}" target="_blank" variant="outline" size="sm" icon="arrow-top-right-on-square">Public Page</flux:button>
                            @endif
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
