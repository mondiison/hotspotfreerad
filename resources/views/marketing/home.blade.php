<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Multi-tenant hotspot, PPPoE, and POS billing for MikroTik networks -- built on FreeRADIUS, with automated payments and router fleet management.">
    <title>{{ config('app.name', 'HotspotFreeRAD') }} -- Hotspot & PPPoE Billing for MikroTik Networks</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white font-sans text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-100" style="--brand: #0d9488; --brand-dark: #0f766e;">

    {{-- Nav --}}
    <header class="sticky top-0 z-40 border-b border-zinc-200/80 bg-white/80 backdrop-blur-lg dark:border-white/10 dark:bg-zinc-950/80">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-sm font-bold text-white" style="background-color: var(--brand)">HF</span>
                <span class="text-base font-semibold tracking-tight">{{ config('app.name', 'HotspotFreeRAD') }}</span>
            </a>

            <nav class="hidden items-center gap-8 text-sm font-medium text-zinc-600 dark:text-zinc-400 md:flex">
                <a href="#features" class="transition hover:text-zinc-950 dark:hover:text-white">Features</a>
                <a href="#how-it-works" class="transition hover:text-zinc-950 dark:hover:text-white">How it works</a>
                <a href="#pricing" class="transition hover:text-zinc-950 dark:hover:text-white">Pricing</a>
                <a href="#faq" class="transition hover:text-zinc-950 dark:hover:text-white">FAQ</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="{{ route('login') }}" class="hidden rounded-lg px-4 py-2 text-sm font-semibold text-zinc-700 transition hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white sm:inline-flex">Admin sign in</a>
                <a href="#contact" class="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90" style="background-color: var(--brand)">
                    Talk to us
                </a>
            </div>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden bg-zinc-950">
            <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(60rem 30rem at 15% -10%, color-mix(in oklab, var(--brand), transparent 78%), transparent), radial-gradient(50rem 30rem at 95% 10%, color-mix(in oklab, var(--brand-dark), transparent 85%), transparent);"></div>

            <div class="relative mx-auto grid max-w-7xl gap-12 px-5 py-20 sm:px-8 sm:py-28 lg:grid-cols-[1.08fr_0.92fr] lg:items-center lg:py-32">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-zinc-300">
                        <span class="h-1.5 w-1.5 rounded-full" style="background-color: var(--brand)"></span>
                        Built for MikroTik + FreeRADIUS networks
                    </span>

                    <h1 class="mt-6 max-w-xl text-4xl font-bold leading-[1.1] tracking-tight text-white sm:text-5xl lg:text-6xl">
                        Run your hotspot business<br class="hidden sm:block"> like a real ISP.
                    </h1>

                    <p class="mt-6 max-w-lg text-lg leading-8 text-zinc-400">
                        {{ config('app.name', 'HotspotFreeRAD') }} handles hotspot, PPPoE, and POS billing end to end -- vouchers, payment gateways, router fleet management, and automated RADIUS provisioning -- so you can focus on growing your network, not patching scripts together.
                    </p>

                    <div class="mt-9 flex flex-wrap items-center gap-4">
                        <a href="#contact" class="inline-flex items-center gap-2 rounded-lg px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-black/20 transition hover:opacity-90" style="background-color: var(--brand)">
                            Talk to our team
                            <flux:icon name="arrow-right" class="size-4" />
                        </a>
                        <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-6 py-3.5 text-sm font-semibold text-white transition hover:bg-white/10">
                            Admin sign in
                        </a>
                    </div>

                    <div class="mt-12 flex flex-wrap items-center gap-x-8 gap-y-3 text-sm text-zinc-500">
                        <span class="flex items-center gap-2"><flux:icon name="check-circle" class="size-4 text-zinc-400" /> Multi-tenant from day one</span>
                        <span class="flex items-center gap-2"><flux:icon name="check-circle" class="size-4 text-zinc-400" /> 5 payment gateways built in</span>
                        <span class="flex items-center gap-2"><flux:icon name="check-circle" class="size-4 text-zinc-400" /> WireGuard router automation</span>
                    </div>
                </div>

                {{-- Illustrative product preview -- static mockup numbers, not live data --}}
                <div class="relative">
                    <div class="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-white/10 to-transparent blur-2xl"></div>
                    <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-zinc-900/80 shadow-2xl shadow-black/40 backdrop-blur">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-3.5">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full bg-rose-400/70"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-400/70"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/70"></span>
                            </div>
                            <span class="text-xs font-medium text-zinc-500">Dashboard preview</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 p-5">
                            @foreach ([
                                ['label' => 'Routers Online', 'value' => '18/18'],
                                ['label' => 'Active Access', 'value' => '1,204'],
                                ['label' => 'Gross Sales', 'value' => 'NGN 842K'],
                                ['label' => 'Success Rate', 'value' => '98.4%'],
                            ] as $stat)
                                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                                    <p class="text-xs font-medium text-zinc-500">{{ $stat['label'] }}</p>
                                    <p class="mt-2 text-xl font-semibold text-white">{{ $stat['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="space-y-3 border-t border-white/10 p-5">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-zinc-400">Router Health</span>
                                <span class="rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs font-medium text-emerald-300">All systems online</span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/5">
                                <div class="h-full w-[94%] rounded-full" style="background-color: var(--brand)"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Trust / integrations bar --}}
        <section class="border-b border-zinc-200 bg-zinc-50 py-10 dark:border-white/10 dark:bg-zinc-900/40">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <p class="text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Works with the tools you already run</p>
                <div class="mt-5 flex flex-wrap items-center justify-center gap-x-10 gap-y-4 text-sm font-semibold text-zinc-400 dark:text-zinc-500">
                    <span>MikroTik RouterOS</span>
                    <span>FreeRADIUS 3</span>
                    <span>WireGuard</span>
                    <span>ZeroTier</span>
                    <span>Flutterwave</span>
                    <span>Paystack</span>
                    <span>Monnify</span>
                    <span>Squad</span>
                    <span>Stripe</span>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-dark)">Everything in one platform</p>
                <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Stop stitching scripts together</h2>
                <p class="mt-4 text-lg leading-7 text-zinc-600 dark:text-zinc-400">From the captive portal to the bank account, every step of running a hotspot or PPPoE network lives in one place.</p>
            </div>

            <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'wifi', 'title' => 'Hotspot & PPPoE billing', 'body' => 'Sell time-based or data-capped packages across hotspot, PPPoE, and POS devices, with vouchers and a free-trial option built in.'],
                    ['icon' => 'signal', 'title' => 'Native MikroTik integration', 'body' => 'Onboard RouterOS devices with auto-generated WireGuard tunnels, API credentials, and one-click provisioning -- no manual scripting.'],
                    ['icon' => 'credit-card', 'title' => 'Five payment gateways', 'body' => 'Flutterwave, Paystack, Monnify, Squad, and Stripe out of the box, plus manual bank transfer and an automated platform wallet.'],
                    ['icon' => 'building-office-2', 'title' => 'True multi-tenancy', 'body' => 'Run multiple ISPs or hotspot brands from one install, each with their own shops, routers, packages, and branded customer portal.'],
                    ['icon' => 'chart-bar', 'title' => 'Real-time monitoring', 'body' => 'Router health, uptime history, live sessions, and alerting the moment a site goes offline or a package nears its data cap.'],
                    ['icon' => 'shield-check', 'title' => 'Built-in security controls', 'body' => 'Two-factor auth, passkeys, staff permission scoping, and a full audit trail of every sensitive admin action.'],
                ] as $feature)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-zinc-300 hover:shadow-md dark:border-white/10 dark:bg-zinc-900 dark:hover:border-white/20">
                        <div class="grid h-11 w-11 place-items-center rounded-xl" style="background-color: color-mix(in oklab, var(--brand), transparent 88%)">
                            <flux:icon :name="$feature['icon']" class="size-5" style="color: var(--brand-dark)" />
                        </div>
                        <h3 class="mt-5 text-lg font-semibold">{{ $feature['title'] }}</h3>
                        <p class="mt-2 leading-6 text-zinc-600 dark:text-zinc-400">{{ $feature['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- How it works --}}
        <section id="how-it-works" class="border-t border-zinc-200 bg-zinc-50 py-20 dark:border-white/10 dark:bg-zinc-900/40 sm:py-28">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-dark)">From router to revenue</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Live in an afternoon, not a month</h2>
                </div>

                <div class="mt-14 grid gap-8 md:grid-cols-4">
                    @foreach ([
                        ['step' => '01', 'title' => 'Add your router', 'body' => 'Paste one generated script into RouterOS -- WireGuard tunnel, API user, and RADIUS client are created automatically.'],
                        ['step' => '02', 'title' => 'Create your packages', 'body' => 'Set speed, uptime, and data limits. They sync straight into FreeRADIUS as group profiles, no manual radcheck edits.'],
                        ['step' => '03', 'title' => 'Customers connect & pay', 'body' => 'The captive portal shows your branded packages. Customers pay by card, transfer, or voucher and get online instantly.'],
                        ['step' => '04', 'title' => 'You get paid', 'body' => 'Track sales and payouts from your dashboard, or let the platform wallet settle your share automatically.'],
                    ] as $item)
                        <div class="relative">
                            <span class="text-5xl font-bold text-zinc-200 dark:text-white/10">{{ $item['step'] }}</span>
                            <h3 class="mt-3 text-lg font-semibold">{{ $item['title'] }}</h3>
                            <p class="mt-2 leading-6 text-zinc-600 dark:text-zinc-400">{{ $item['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Pricing --}}
        <section id="pricing" class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-dark)">Simple, transparent pricing</p>
                <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Pay for what your network needs</h2>
            </div>

            @if ($plans->isNotEmpty())
                @php
                    $pricingGridClass = match (true) {
                        $plans->count() === 1 => 'lg:grid-cols-1',
                        $plans->count() === 2 => 'lg:grid-cols-2',
                        default => 'lg:grid-cols-3',
                    };
                @endphp
                <div class="mt-14 grid gap-6 {{ $pricingGridClass }}">
                    @foreach ($plans as $plan)
                        <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-7 shadow-sm dark:border-white/10 dark:bg-zinc-900 {{ $loop->iteration === 2 && $plans->count() >= 2 ? 'ring-2 ring-offset-2 ring-offset-white dark:ring-offset-zinc-950' : '' }}" @if ($loop->iteration === 2 && $plans->count() >= 2) style="--tw-ring-color: var(--brand)" @endif>
                            <h3 class="text-lg font-semibold">{{ $plan->name }}</h3>
                            <p class="mt-4 flex items-baseline gap-1">
                                <span class="text-4xl font-bold tracking-tight">{{ $plan->currency }} {{ number_format($plan->monthly_price, 0) }}</span>
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">/month</span>
                            </p>

                            <ul class="mt-6 space-y-3 text-sm text-zinc-600 dark:text-zinc-400">
                                @if ($plan->shop_limit)
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> Up to {{ number_format($plan->shop_limit) }} {{ \Illuminate\Support\Str::plural('shop', $plan->shop_limit) }}</li>
                                @else
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> Unlimited shops</li>
                                @endif
                                @if ($plan->router_limit)
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> Up to {{ number_format($plan->router_limit) }} {{ \Illuminate\Support\Str::plural('router', $plan->router_limit) }}</li>
                                @else
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> Unlimited routers</li>
                                @endif
                                @if ($plan->package_limit)
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> Up to {{ number_format($plan->package_limit) }} {{ \Illuminate\Support\Str::plural('package', $plan->package_limit) }}</li>
                                @else
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> Unlimited packages</li>
                                @endif
                                @if ($plan->supports_wallet)
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> Automated platform wallet</li>
                                @endif
                                @foreach ((array) ($plan->features ?? []) as $feature)
                                    <li class="flex items-start gap-2"><flux:icon name="check" class="mt-0.5 size-4 shrink-0" style="color: var(--brand-dark)" /> {{ $feature }}</li>
                                @endforeach
                            </ul>

                            <a href="#contact" class="mt-8 inline-flex items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold text-white transition hover:opacity-90" style="background-color: var(--brand)">
                                Get started
                            </a>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mx-auto mt-14 max-w-lg rounded-2xl border border-zinc-200 bg-zinc-50 p-10 text-center dark:border-white/10 dark:bg-zinc-900">
                    <p class="text-lg font-semibold">Pricing tailored to your network</p>
                    <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">Tell us how many locations and routers you're running, and we'll put together a plan that fits.</p>
                    <a href="#contact" class="mt-6 inline-flex items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold text-white transition hover:opacity-90" style="background-color: var(--brand)">
                        Talk to our team
                    </a>
                </div>
            @endif
        </section>

        {{-- FAQ --}}
        <section id="faq" class="border-t border-zinc-200 bg-zinc-50 py-20 dark:border-white/10 dark:bg-zinc-900/40 sm:py-28">
            <div class="mx-auto max-w-3xl px-5 sm:px-8">
                <div class="text-center">
                    <p class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-dark)">Questions</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Frequently asked</h2>
                </div>

                <flux:accordion class="mt-12 rounded-2xl border border-zinc-200 bg-white px-6 shadow-sm dark:border-white/10 dark:bg-zinc-900" transition>
                    <flux:accordion.item heading="Do I need my own payment gateway account?">
                        No. You can connect your own Flutterwave, Paystack, Monnify, Squad, or Stripe account, or use the platform's own wallet mode and get paid out automatically without managing a gateway account yourself.
                    </flux:accordion.item>
                    <flux:accordion.item heading="What MikroTik hardware does this support?">
                        Any RouterOS 6 or 7 device with WireGuard support. Onboarding generates a ready-to-paste configuration script for your exact router, including wireless, VLAN, and firewall setup.
                    </flux:accordion.item>
                    <flux:accordion.item heading="Can customers pay by card, transfer, or USSD?">
                        Yes -- depending on the gateway connected, customers can pay by card, bank transfer, OPay, or USSD directly from the captive portal, with vouchers and a free trial option available too.
                    </flux:accordion.item>
                    <flux:accordion.item heading="Can I run more than one brand or ISP from one account?">
                        Yes. The platform is multi-tenant from the ground up -- each tenant gets its own shops, routers, packages, and branded public-facing site.
                    </flux:accordion.item>
                </flux:accordion>
            </div>
        </section>

        {{-- Final CTA --}}
        <section id="contact" class="relative overflow-hidden bg-zinc-950 py-20 sm:py-28">
            <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(50rem 25rem at 50% 0%, color-mix(in oklab, var(--brand), transparent 85%), transparent);"></div>
            <div class="relative mx-auto max-w-2xl px-5 text-center sm:px-8">
                <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Ready to run your network like a business?</h2>
                <p class="mt-4 text-lg leading-7 text-zinc-400">Tell us about your network and we'll help you get your first router live.</p>
                <div class="mt-9 flex flex-wrap items-center justify-center gap-4">
                    <a href="mailto:{{ config('mail.from.address') }}" class="inline-flex items-center gap-2 rounded-lg px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-black/20 transition hover:opacity-90" style="background-color: var(--brand)">
                        <flux:icon name="envelope" class="size-4" />
                        {{ config('mail.from.address') }}
                    </a>
                    <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-6 py-3.5 text-sm font-semibold text-white transition hover:bg-white/10">
                        Admin sign in
                    </a>
                </div>
            </div>
        </section>
    </main>

    {{-- Footer --}}
    <footer class="border-t border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-950">
        <div class="mx-auto max-w-7xl px-5 py-12 sm:px-8">
            <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-xs font-bold text-white" style="background-color: var(--brand)">HF</span>
                        <span class="text-sm font-semibold">{{ config('app.name', 'HotspotFreeRAD') }}</span>
                    </a>
                    <p class="mt-4 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Hotspot, PPPoE, and POS billing for MikroTik networks, built on FreeRADIUS.</p>
                </div>

                <div>
                    <p class="text-sm font-semibold">Product</p>
                    <ul class="mt-4 space-y-3 text-sm text-zinc-500 dark:text-zinc-400">
                        <li><a href="#features" class="hover:text-zinc-950 dark:hover:text-white">Features</a></li>
                        <li><a href="#how-it-works" class="hover:text-zinc-950 dark:hover:text-white">How it works</a></li>
                        <li><a href="#pricing" class="hover:text-zinc-950 dark:hover:text-white">Pricing</a></li>
                        <li><a href="#faq" class="hover:text-zinc-950 dark:hover:text-white">FAQ</a></li>
                    </ul>
                </div>

                <div>
                    <p class="text-sm font-semibold">Account</p>
                    <ul class="mt-4 space-y-3 text-sm text-zinc-500 dark:text-zinc-400">
                        <li><a href="{{ route('login') }}" class="hover:text-zinc-950 dark:hover:text-white">Admin sign in</a></li>
                        <li><a href="{{ route('password.request') }}" class="hover:text-zinc-950 dark:hover:text-white">Forgot password</a></li>
                    </ul>
                </div>

                <div>
                    <p class="text-sm font-semibold">Contact</p>
                    <ul class="mt-4 space-y-3 text-sm text-zinc-500 dark:text-zinc-400">
                        <li><a href="mailto:{{ config('mail.from.address') }}" class="hover:text-zinc-950 dark:hover:text-white">{{ config('mail.from.address') }}</a></li>
                    </ul>
                </div>
            </div>

            <div class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-zinc-200 pt-8 text-sm text-zinc-500 dark:border-white/10 dark:text-zinc-400 sm:flex-row">
                <p>&copy; {{ now()->year }} {{ config('app.name', 'HotspotFreeRAD') }}. All rights reserved.</p>
            </div>
        </div>
    </footer>

    @fluxScripts
</body>
</html>
