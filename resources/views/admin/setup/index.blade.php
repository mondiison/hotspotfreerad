<x-layouts.admin
    title="Setup Center"
    heading="Setup Center"
    subheading="A practical path from tenant setup to a live MikroTik hotspot."
>
    <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
            <div>
                <p class="text-sm font-medium text-zinc-500">Launch Readiness</p>
                <h2 class="mt-2 text-2xl font-semibold">{{ $completedCount }} / {{ count($steps) }} setup checks complete</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">
                    Follow these phases in order. The last test is intentionally manual because a real captive portal check should happen from a phone connected to the MikroTik hotspot.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button href="{{ route('admin.routers.index') }}" wire:navigate variant="outline" icon="signal">Routers</flux:button>
                <flux:button href="{{ route('admin.payment-settings.index') }}" wire:navigate variant="outline" icon="credit-card">Payments</flux:button>
                <flux:button href="{{ route('admin.packages.index') }}" wire:navigate variant="primary" icon="radio">Packages</flux:button>
            </div>
        </div>

        <div class="mt-5 h-2 overflow-hidden rounded-full bg-zinc-100">
            <div class="h-full rounded-full bg-zinc-950" style="width: {{ round(($completedCount / max(count($steps), 1)) * 100) }}%"></div>
        </div>
    </section>

    <section class="mt-6 grid gap-4 lg:grid-cols-3">
        @foreach ($steps as $step)
            <article class="flex min-h-64 flex-col rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $step['phase'] }}</p>
                        <h3 class="mt-2 text-base font-semibold">{{ $step['label'] }}</h3>
                    </div>
                    <flux:badge :color="$step['complete'] ? 'green' : 'amber'">
                        {{ $step['complete'] ? 'Ready' : 'Pending' }}
                    </flux:badge>
                </div>

                <p class="mt-4 flex-1 text-sm leading-6 text-zinc-600">{{ $step['description'] }}</p>
                <p class="mt-4 rounded-md bg-zinc-50 p-3 text-xs leading-5 text-zinc-500">{{ $step['hint'] }}</p>

                <flux:button
                    href="{{ route($step['route'], $step['route_parameters'] ?? []) }}"
                    wire:navigate
                    variant="{{ $step['complete'] ? 'outline' : 'primary' }}"
                    size="sm"
                    class="mt-4"
                >
                    {{ $step['action'] }}
                </flux:button>
            </article>
        @endforeach
    </section>

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
            <div>
                <p class="text-sm font-medium text-zinc-500">Scheduler Health</p>
                <h2 class="mt-2 text-xl font-semibold">{{ $schedulerHealth['label'] }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">{{ $schedulerHealth['description'] }}</p>
            </div>
            <flux:badge :color="$schedulerHealth['is_healthy'] ? 'green' : ($schedulerHealth['last_run_at'] ? 'amber' : 'zinc')">
                {{ $schedulerHealth['is_healthy'] ? 'Cron active' : 'Check cron' }}
            </flux:badge>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-[0.95fr_1.05fr]">
            <div class="rounded-lg border border-zinc-200 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Last heartbeat</p>
                <p class="mt-2 text-lg font-semibold">
                    {{ $schedulerHealth['last_run_at'] ? $schedulerHealth['last_run_at']->format('M j, Y g:i A') : 'No heartbeat yet' }}
                </p>
                <p class="mt-2 text-sm leading-6 text-zinc-600">
                    {{ $schedulerHealth['last_seen'] ? 'Seen '.$schedulerHealth['last_seen'].'.' : 'Run the scheduler command or wait for cron after adding it.' }}
                </p>
            </div>

            <div class="rounded-lg border border-zinc-200 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Pi cron entry</p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-zinc-950 p-3 text-xs leading-5 text-white"><code>{{ $schedulerHealth['command'] }}</code></pre>
                <p class="mt-3 text-sm leading-6 text-zinc-600">
                    This keeps hotspot expiry, PPPoE expiry, security pruning, and future queued maintenance moving automatically.
                </p>
            </div>
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm" id="pppoe">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-sm font-medium text-zinc-500">Provisioning Methods</p>
                <h2 class="mt-2 text-xl font-semibold">Hotspot and PPPoE operating paths</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">
                    Use Hotspot for captive portal packages. Use PPPoE when each subscriber has a fixed account on a CPE, ONT, or home router.
                </p>
            </div>
            <flux:badge color="blue">FreeRADIUS foundation supports both</flux:badge>
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-4">
            @foreach ($provisioningMethods as $method)
                <article class="rounded-lg border border-zinc-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-sm font-semibold">{{ $method['label'] }}</h3>
                        <flux:badge :color="$method['status'] === 'Live' ? 'green' : 'blue'" size="sm">{{ $method['status'] }}</flux:badge>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-zinc-600">{{ $method['description'] }}</p>

                    <dl class="mt-4 space-y-3 text-xs leading-5">
                        <div>
                            <dt class="font-medium text-zinc-950">Customer identity</dt>
                            <dd class="mt-1 text-zinc-500">{{ $method['customer_identity'] }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-950">Router work</dt>
                            <dd class="mt-1 text-zinc-500">{{ $method['router_work'] }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-950">Billing work</dt>
                            <dd class="mt-1 text-zinc-500">{{ $method['billing_work'] }}</dd>
                        </div>
                    </dl>

                    <flux:button
                        href="{{ route($method['action_route'], $method['action_parameters']) }}"
                        wire:navigate
                        variant="{{ $method['status'] === 'Live' ? 'primary' : 'outline' }}"
                        size="sm"
                        class="mt-4"
                    >
                        {{ $method['action'] }}
                    </flux:button>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
            <div>
                <p class="text-sm font-medium text-zinc-500">PPPoE Wizard</p>
                <h2 class="mt-2 text-xl font-semibold">Subscriber provisioning flow</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">
                    Use this flow for fixed broadband subscribers: create the PPPoE package, provision the customer, hand off the CPE settings, then inspect accounting and renew when due.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button href="{{ route('admin.packages.index') }}" wire:navigate variant="outline" size="sm" icon="radio">Review plans</flux:button>
                <flux:button href="{{ route('admin.pppoe-subscribers.index') }}" wire:navigate variant="primary" size="sm" icon="wifi">Manage PPPoE customers</flux:button>
            </div>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-4">
            @foreach ($pppoeWizard as $step)
                <article class="rounded-lg border border-zinc-200 p-4">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-950 text-xs font-semibold text-white">{{ $loop->iteration }}</span>
                    <h3 class="mt-4 text-sm font-semibold">{{ $step['title'] }}</h3>
                    <p class="mt-3 text-sm leading-6 text-zinc-600">{{ $step['detail'] }}</p>
                    <pre class="mt-4 overflow-x-auto rounded-md bg-zinc-950 p-3 text-xs leading-5 text-white"><code>{{ $step['example'] }}</code></pre>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-zinc-500">Payment Readiness</p>
                    <h2 class="mt-2 text-xl font-semibold">Flutterwave channels</h2>
                    <p class="mt-2 text-sm leading-6 text-zinc-600">Customer money should go through tenant shop credentials. Each payment channel needs a different credential.</p>
                </div>
                <flux:button href="{{ route('admin.payment-settings.index') }}" wire:navigate variant="outline" size="sm" icon="credit-card">Setup</flux:button>
            </div>

            <div class="mt-5 space-y-3">
                @foreach ([
                    ['label' => 'OPay and bank transfer', 'value' => $paymentReady['opay_transfer'], 'hint' => 'Needs v4 Client ID and Client Secret.'],
                    ['label' => 'Card checkout', 'value' => $paymentReady['card'], 'hint' => 'Needs v3 Secret Key, for example FLWSECK_TEST-...'],
                    ['label' => 'Automatic webhook confirmation', 'value' => $paymentReady['webhook'], 'hint' => 'Needs Flutterwave secret hash/verif-hash.'],
                ] as $channel)
                    <div class="rounded-lg border border-zinc-200 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium">{{ $channel['label'] }}</p>
                            <flux:badge :color="$channel['value'] > 0 ? 'green' : 'amber'">{{ $channel['value'] }} shop{{ $channel['value'] === 1 ? '' : 's' }}</flux:badge>
                        </div>
                        <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $channel['hint'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-zinc-500">Router Test</p>
                    <h2 class="mt-2 text-xl font-semibold">Captive portal checklist</h2>
                    <p class="mt-2 text-sm leading-6 text-zinc-600">Use these checks after the RouterOS script and login.html template have been applied.</p>
                </div>
                @if ($firstRouter)
                    <flux:button href="{{ route('admin.routers.show', $firstRouter) }}" wire:navigate variant="outline" size="sm" icon="command-line">Script</flux:button>
                @endif
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach ([
                    'Phone receives an IP address from the hotspot DHCP pool.',
                    'Phone opens the package selection page instead of a 404 page.',
                    'Selected package starts Flutterwave checkout for the enabled channel.',
                    'After payment, the access granted page connects the device automatically.',
                    'FreeRADIUS accounting starts showing the online session.',
                    'Dashboard Users Online and router status update after accounting traffic.',
                ] as $check)
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm leading-6 text-zinc-600">
                        <span class="mb-3 flex h-7 w-7 items-center justify-center rounded-full bg-zinc-950 text-xs font-semibold text-white">{{ $loop->iteration }}</span>
                        {{ $check }}
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col justify-between gap-3 md:flex-row md:items-center">
            <div>
                <p class="text-sm font-medium text-zinc-500">Locations</p>
                <h2 class="mt-2 text-xl font-semibold">Shop setup snapshot</h2>
            </div>
            <flux:button href="{{ route('admin.shops.index') }}" wire:navigate variant="outline" size="sm" icon="building-storefront">Manage shops</flux:button>
        </div>

        <div class="mt-5 overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200">
            <table class="min-w-[760px] w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Shop</th>
                        <th class="px-4 py-3 font-medium">Routers</th>
                        <th class="px-4 py-3 font-medium">Packages</th>
                        <th class="px-4 py-3 font-medium">OPay/Transfer</th>
                        <th class="px-4 py-3 font-medium">Card</th>
                        <th class="px-4 py-3 font-medium">Webhook</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($shops as $shop)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $shop->name }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $shop->tenant?->company_name }}{{ $shop->location_city ? ' - '.$shop->location_city : '' }}</p>
                            </td>
                            <td class="px-4 py-3">{{ number_format($shop->routers_count) }}</td>
                            <td class="px-4 py-3">{{ number_format($shop->packages_count) }}</td>
                            <td class="px-4 py-3"><flux:badge :color="$shop->hasCompleteFlutterwaveCredentials() ? 'green' : 'amber'">{{ $shop->hasCompleteFlutterwaveCredentials() ? 'Ready' : 'Missing' }}</flux:badge></td>
                            <td class="px-4 py-3"><flux:badge :color="$shop->hasFlutterwaveHostedCheckoutKey() ? 'green' : 'amber'">{{ $shop->hasFlutterwaveHostedCheckoutKey() ? 'Ready' : 'Missing' }}</flux:badge></td>
                            <td class="px-4 py-3"><flux:badge :color="$shop->hasFlutterwaveWebhookSecret() ? 'green' : 'zinc'">{{ $shop->hasFlutterwaveWebhookSecret() ? 'Ready' : 'Missing' }}</flux:badge></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-zinc-500">Create a shop first, then continue with routers, packages, and payments.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.admin>
