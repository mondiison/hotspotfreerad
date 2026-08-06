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
    class="min-h-screen overflow-x-hidden bg-zinc-100 text-zinc-950 antialiased"
    x-data="{
        mobileSidebarOpen: false,
        sidebarCollapsed: localStorage.getItem('adminSidebarCollapsed') === '1',
        setSidebarCollapsed(value) {
            this.sidebarCollapsed = value;
            localStorage.setItem('adminSidebarCollapsed', value ? '1' : '0');
        },
        headerAccountMenuOpen: false,
    }"
>
    <div class="min-h-screen max-w-full overflow-x-hidden lg:flex">
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
                    $topLinks = [
                        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'squares-2x2'],
                        ['label' => 'Setup', 'route' => 'admin.setup.index', 'icon' => 'rocket-launch'],
                    ];

                    $groups = [
                        [
                            'label' => 'Network',
                            'links' => [
                                ['label' => 'Routers', 'route' => 'admin.routers.index', 'icon' => 'signal'],
                                ['label' => 'Topology', 'route' => 'admin.topology.index', 'icon' => 'share'],
                                ['label' => 'Packages', 'route' => 'admin.packages.index', 'icon' => 'radio'],
                                ['label' => 'POS Devices', 'route' => 'admin.pos-devices.index', 'icon' => 'device-phone-mobile'],
                                ['label' => 'Trusted Wi-Fi', 'route' => 'admin.trusted-wifi-devices.index', 'icon' => 'shield-check'],
                                ['label' => 'PPPoE', 'route' => 'admin.pppoe-subscribers.index', 'icon' => 'wifi'],
                            ],
                        ],
                        [
                            'label' => 'Customers',
                            'links' => [
                                ['label' => 'Access', 'route' => 'admin.subscriptions.index', 'icon' => 'key'],
                                ['label' => 'Vouchers', 'route' => 'admin.vouchers.index', 'icon' => 'ticket'],
                            ],
                        ],
                        [
                            'label' => 'Money',
                            'links' => [
                                ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'banknotes'],
                                ['label' => 'Billing', 'route' => 'admin.billing.index', 'icon' => 'credit-card'],
                                ['label' => 'Payment Setup', 'route' => 'admin.payment-settings.index', 'icon' => 'building-library', 'tenant_admin' => true],
                                ['label' => 'Expenses', 'route' => 'admin.expenses.index', 'icon' => 'receipt-percent'],
                                ['label' => 'Reports', 'route' => 'admin.reports.sales', 'icon' => 'chart-bar'],
                            ],
                        ],
                        [
                            'label' => 'Organization',
                            'links' => [
                                ['label' => 'Tenants', 'route' => 'admin.tenants.index', 'icon' => 'building-storefront', 'super_admin' => true],
                                ['label' => 'Shops', 'route' => 'admin.shops.index', 'icon' => 'building-storefront'],
                                ['label' => 'Users', 'route' => 'admin.users.index', 'icon' => 'users'],
                                ['label' => 'Brand', 'route' => 'admin.brand.edit', 'icon' => 'swatch', 'tenant_admin' => true],
                            ],
                        ],
                        [
                            'label' => 'Security',
                            'links' => [
                                ['label' => 'Security', 'route' => 'admin.security.index', 'icon' => 'shield-check', 'super_admin' => true],
                                ['label' => 'Activity', 'route' => 'admin.security-activity.index', 'icon' => 'clock'],
                            ],
                        ],
                    ];

                    $visibleFor = fn (array $link): bool =>
                        ! (($link['super_admin'] ?? false) && ! auth()->user()?->isSuperAdmin())
                        && ! (($link['tenant_admin'] ?? false) && auth()->user()?->isSuperAdmin())
                        && (auth()->user()?->canAccessRoute($link['route']) ?? false);

                    $sectionPatternFor = fn (string $route): string => $route === 'admin.dashboard'
                        ? $route
                        : \Illuminate\Support\Str::beforeLast($route, '.').'.*';
                @endphp

                @foreach ($topLinks as $link)
                    @continue(! $visibleFor($link))
                    @php($active = request()->routeIs($sectionPatternFor($link['route'])))
                    <a
                        href="{{ route($link['route']) }}"
                        wire:navigate
                        title="{{ $link['label'] }}"
                        class="flex items-center gap-3 rounded-md px-3 py-2 {{ $active ? 'bg-zinc-950 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                        :class="{ 'lg:justify-center': sidebarCollapsed }"
                    >
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md {{ $active ? 'bg-white/10' : 'bg-zinc-100' }}">
                            <x-dynamic-component :component="'flux::icon.'.$link['icon']" class="size-4" />
                        </span>
                        <span :class="{ 'lg:hidden': sidebarCollapsed }">{{ $link['label'] }}</span>
                    </a>
                @endforeach

                @foreach ($groups as $group)
                    @php($visibleLinks = collect($group['links'])->filter($visibleFor)->values())
                    @continue($visibleLinks->isEmpty())
                    @php($groupActive = $visibleLinks->contains(fn ($link) => request()->routeIs($sectionPatternFor($link['route']))))

                    <div class="pt-3" x-data="{ groupOpen: {{ $groupActive ? 'true' : 'false' }} }">
                        <button
                            type="button"
                            @click="groupOpen = ! groupOpen"
                            class="flex w-full items-center justify-between rounded-md px-3 py-1.5 text-xs font-semibold tracking-wide text-zinc-400 uppercase hover:text-zinc-600"
                            :class="{ 'lg:justify-center lg:px-0': sidebarCollapsed }"
                        >
                            <span :class="{ 'lg:hidden': sidebarCollapsed }">{{ $group['label'] }}</span>
                            <span class="hidden text-zinc-300" :class="{ 'lg:block': sidebarCollapsed }">&middot;&middot;&middot;</span>
                            <flux:icon.chevron-down class="size-3.5 shrink-0 transition-transform" x-bind:class="{ '-rotate-90': ! groupOpen, 'lg:hidden': sidebarCollapsed }" />
                        </button>

                        <div x-show="groupOpen || sidebarCollapsed" x-transition.opacity.duration.150ms class="mt-1 space-y-1">
                            @foreach ($visibleLinks as $link)
                                @php($active = request()->routeIs($sectionPatternFor($link['route'])))
                                <a
                                    href="{{ route($link['route']) }}"
                                    wire:navigate
                                    title="{{ $link['label'] }}"
                                    class="flex items-center gap-3 rounded-md px-3 py-2 {{ $active ? 'bg-zinc-950 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                                    :class="{ 'lg:justify-center': sidebarCollapsed }"
                                >
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md {{ $active ? 'bg-white/10' : 'bg-zinc-100' }}">
                                        <x-dynamic-component :component="'flux::icon.'.$link['icon']" class="size-4" />
                                    </span>
                                    <span :class="{ 'lg:hidden': sidebarCollapsed }">{{ $link['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>
        </aside>

        <main class="min-w-0 max-w-full flex-1 overflow-x-hidden">
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
                                    <flux:badge :color="auth()->user()->isSuperAdmin() ? 'blue' : (auth()->user()->isTenantStaff() ? 'amber' : 'green')">
                                        {{ auth()->user()->isSuperAdmin() ? 'Platform Admin' : (auth()->user()->isTenantStaff() ? 'Staff' : 'Tenant Admin') }}
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
                            <livewire:admin.notification-bell />

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

            <div class="min-w-0 max-w-full overflow-x-hidden px-5 py-6 lg:px-8">
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
