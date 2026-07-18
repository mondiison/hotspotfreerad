<x-layouts.admin
    title="Dashboard"
    heading="Dashboard"
    subheading="Tenant, router, package, and access overview for your hotspot network."
>
    @php
        $formatBytes = function (?int $bytes): string {
            if (! $bytes) {
                return '0 B';
            }

            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $index = 0;
            $value = (float) $bytes;

            while ($value >= 1024 && $index < count($units) - 1) {
                $value /= 1024;
                $index++;
            }

            return number_format($value, $index === 0 ? 0 : 1).' '.$units[$index];
        };
    @endphp

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['label' => 'Tenants', 'value' => $tenantCount, 'hint' => auth()->user()->isSuperAdmin() ? 'Total platform customers' : 'Your assigned tenant'],
            ['label' => 'Locations', 'value' => $shopCount, 'hint' => 'Active hotspot shops/sites'],
            ['label' => 'Routers Online', 'value' => "{$onlineRouterCount}/{$routerCount}", 'hint' => 'Only routers with active accounting sessions are counted online'],
            ['label' => 'Active Plans', 'value' => "{$activePackageCount}/{$packageCount}", 'hint' => 'Published packages customers can select'],
            ['label' => 'Active Access', 'value' => $activeSubscriptionCount, 'hint' => 'Unexpired app subscriptions'],
            ['label' => 'Users Online', 'value' => is_null($onlineUserCount) ? 'Not ready' : $onlineUserCount, 'hint' => $radiusAccountingReady ? 'Unique active RADIUS usernames' : 'radacct table has not been created'],
            ['label' => 'RADIUS Sessions', 'value' => is_null($activeSessionCount) ? 'Not ready' : $activeSessionCount, 'hint' => $radiusAccountingReady ? 'Live accounting sessions' : 'radacct table has not been created'],
            ['label' => 'Usage Today', 'value' => $formatBytes($todayUsageBytes), 'hint' => 'Upload + download from sessions started today'],
            ['label' => 'Total Usage', 'value' => $formatBytes($totalUsageBytes), 'hint' => 'All accounting traffic for scoped routers'],
            ['label' => 'Paid Revenue', 'value' => 'NGN '.number_format($paidRevenue, 2), 'hint' => 'Successful payments recorded'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-3xl font-semibold">{{ is_numeric($stat['value']) ? number_format($stat['value']) : $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Router Health</h2>
                    <p class="mt-1 text-sm text-zinc-500">Status is refreshed from recent FreeRADIUS accounting activity.</p>
                </div>
                <a href="{{ route('admin.routers.index') }}" class="rounded-md border border-zinc-200 px-3 py-2 text-sm hover:bg-zinc-50">View all</a>
            </div>

            <div class="mt-5 overflow-hidden rounded-lg border border-zinc-200">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Router</th>
                            <th class="px-4 py-3 font-medium">Shop</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($routerHealth as $router)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $router->name }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $router->shop->name }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs font-medium {{ $router->detected_status === 'Online' ? 'bg-emerald-50 text-emerald-700' : ($router->detected_status === 'Recently seen' ? 'bg-blue-50 text-blue-700' : 'bg-zinc-100 text-zinc-600') }}">
                                        {{ $router->detected_status ?? 'Unknown' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-600">{{ $router->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500">No routers have been registered yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold">Setup Progress</h2>
            <p class="mt-1 text-sm text-zinc-500">The clean path from platform setup to live customer access.</p>

            <div class="mt-5 space-y-3">
                @foreach ($setupSteps as $step)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium {{ $step['complete'] ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-500' }}">
                                {{ $step['complete'] ? 'OK' : $loop->iteration }}
                            </span>
                            <p class="text-sm font-medium">{{ $step['label'] }}</p>
                        </div>

                        @if (! $step['complete'] && $step['route'])
                            <a href="{{ route($step['route']) }}" class="text-sm font-medium text-zinc-950 underline decoration-zinc-300 underline-offset-4">Start</a>
                        @else
                            <span class="text-sm text-zinc-500">{{ $step['complete'] ? 'Done' : 'Pending' }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Users Online</h2>
                <p class="mt-1 text-sm text-zinc-500">Live sessions from FreeRADIUS accounting, grouped by routers this admin can access.</p>
            </div>
        </div>

        <div class="mt-5 overflow-hidden rounded-lg border border-zinc-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">User / Device</th>
                        <th class="px-4 py-3 font-medium">Router</th>
                        <th class="px-4 py-3 font-medium">Framed IP</th>
                        <th class="px-4 py-3 font-medium">Online Since</th>
                        <th class="px-4 py-3 font-medium">Usage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($onlineSessions as $session)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $session->username }}</p>
                                <p class="mt-1 font-mono text-xs text-zinc-500">{{ $session->callingstationid ?: 'No MAC reported' }}</p>
                            </td>
                            <td class="px-4 py-3 text-zinc-600">
                                <p>{{ $session->router_name }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $session->shop_name ?: $session->nasipaddress }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $session->framedipaddress ?: 'None' }}</td>
                            <td class="px-4 py-3 text-zinc-600">{{ $session->acctstarttime ? \Illuminate\Support\Carbon::parse($session->acctstarttime)->diffForHumans() : 'Unknown' }}</td>
                            <td class="px-4 py-3 font-medium">{{ $formatBytes($session->total_bytes) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-zinc-500">
                                {{ $radiusAccountingReady ? 'No users are online right now.' : 'FreeRADIUS accounting is not available yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Recent Access Grants</h2>
                <p class="mt-1 text-sm text-zinc-500">Latest subscriptions created from the captive portal or package flow.</p>
            </div>
            <a href="{{ route('admin.packages.index') }}" class="rounded-md border border-zinc-200 px-3 py-2 text-sm hover:bg-zinc-50">Manage plans</a>
        </div>

        <div class="mt-5 overflow-hidden rounded-lg border border-zinc-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Device</th>
                        <th class="px-4 py-3 font-medium">Package</th>
                        <th class="px-4 py-3 font-medium">Shop</th>
                        <th class="px-4 py-3 font-medium">Expires</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($recentSubscriptions as $subscription)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $subscription->mac_address }}</td>
                            <td class="px-4 py-3 text-zinc-600">{{ $subscription->package->name }}</td>
                            <td class="px-4 py-3 text-zinc-600">{{ $subscription->shop->name }}</td>
                            <td class="px-4 py-3 text-zinc-600">{{ $subscription->expires_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500">No access grants have been created yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.admin>
