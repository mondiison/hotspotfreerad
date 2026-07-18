<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - {{ $tenant->company_name ?? 'HotspotFreeRAD' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased" style="--brand: {{ $tenant->brand_color ?? '#047857' }}">
    @php
        $logoImageUrl = $tenant?->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
    @endphp

    <main class="flex min-h-screen items-center justify-center px-5 py-10 sm:px-8">
        <section class="grid w-full max-w-6xl overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm lg:grid-cols-[1.05fr_0.95fr]">
            <div class="hidden bg-zinc-950 p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-12">
                <div class="flex items-center gap-3">
                    @if ($logoImageUrl)
                        <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-white/10 bg-white object-cover">
                    @else
                        <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">HF</span>
                    @endif
                    <div>
                        <p class="font-semibold">{{ $tenant->company_name ?? 'HotspotFreeRAD' }}</p>
                        <p class="mt-1 text-xs text-zinc-400">{{ $tenant ? 'Tenant admin workspace' : 'Platform operations console' }}</p>
                    </div>
                </div>

                <div class="max-w-lg">
                    <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand)">{{ $tenant ? 'Workspace access' : 'SaaS operations' }}</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-tight xl:text-5xl">{{ $tenant ? 'Manage locations, plans, routers, and access from one workspace.' : 'Operate tenants, billing, routers, and captive portal access from one console.' }}</h1>
                    <p class="mt-5 text-sm leading-6 text-zinc-300">
                        {{ $tenant->public_site_tagline ?? 'Built for hotspot operators that need tenant separation, branded public pages, payment routing, and RADIUS-backed access control.' }}
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-3 text-sm">
                    @foreach ([
                        ['value' => '01', 'label' => 'Tenant scope'],
                        ['value' => '02', 'label' => 'Router health'],
                        ['value' => '03', 'label' => 'Billing control'],
                    ] as $item)
                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                            <p class="text-xl font-semibold">{{ $item['value'] }}</p>
                            <p class="mt-2 text-xs text-zinc-400">{{ $item['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center justify-center px-5 py-10 sm:px-8 lg:px-12">
                <div class="w-full max-w-md">
                    <div class="mb-8">
                        <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant ? $tenant->company_name : 'Welcome back' }}</p>
                        <h2 class="mt-2 text-3xl font-semibold">Sign in to {{ $tenant ? 'your workspace' : 'HotspotFreeRAD' }}</h2>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">{{ $tenant ? 'Use the tenant admin account linked to this workspace.' : 'Use your super admin or tenant admin credentials.' }}</p>
                    </div>

                    <form method="POST" action="{{ route('login.store') }}" class="rounded-lg border border-zinc-200 bg-zinc-50 p-6">
                    @csrf
                    @if ($tenant)
                        <input type="hidden" name="tenant_slug" value="{{ $tenant->slug }}">
                    @endif

                    <div class="space-y-5">
                        <label class="block">
                            <span class="text-sm font-medium">Email</span>
                            <input type="email" name="email" value="{{ old('email') }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required autofocus>
                            @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="flex items-center justify-between gap-3 text-sm font-medium">
                                Password
                                <a href="{{ route('password.request') }}" class="text-xs font-semibold hover:opacity-80" style="color: var(--brand)">Forgot password?</a>
                            </span>
                            <input type="password" name="password" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                            @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="flex items-center gap-2 text-sm text-zinc-700">
                            <input type="checkbox" name="remember" value="1" class="rounded border-zinc-300">
                            Remember this device
                        </label>
                    </div>

                        <button class="mt-6 w-full rounded-md px-4 py-2.5 text-sm font-medium text-white" style="background-color: var(--brand)">Sign In</button>
                    </form>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
