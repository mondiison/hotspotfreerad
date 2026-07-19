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

    @if ($tenantWorkspaceSummary)
        <section class="mb-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-zinc-500">Tenant Workspace</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <h2 class="truncate text-2xl font-semibold">{{ $tenantWorkspaceSummary['company_name'] }}</h2>
                        <flux:badge :color="$tenantWorkspaceSummary['public_site_enabled'] ? 'green' : 'zinc'">
                            {{ $tenantWorkspaceSummary['public_site_enabled'] ? 'Public site live' : 'Public site disabled' }}
                        </flux:badge>
                    </div>
                    <p class="mt-2 text-sm text-zinc-500">/{{ $tenantWorkspaceSummary['slug'] }} - {{ $tenantWorkspaceSummary['owner_email'] }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button href="{{ route('admin.brand.edit') }}" variant="outline" icon="swatch">Brand</flux:button>
                    <flux:button href="{{ route('admin.payment-settings.index') }}" variant="outline" icon="credit-card">Payment Setup</flux:button>
                    <flux:button href="{{ $tenantWorkspaceSummary['public_url'] }}" target="_blank" variant="primary" icon="arrow-top-right-on-square">Public Page</flux:button>
                </div>
            </div>
        </section>
    @endif

    @if ($tenantLaunchChecklist)
        <section class="mb-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                <div>
                    <p class="text-sm font-medium text-zinc-500">Launch Checklist</p>
                    <h2 class="mt-2 text-xl font-semibold">Tenant setup path</h2>
                </div>
                <flux:badge color="zinc">{{ collect($tenantLaunchChecklist)->where('complete', true)->count() }} / {{ count($tenantLaunchChecklist) }} complete</flux:badge>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-5">
                @foreach ($tenantLaunchChecklist as $item)
                    <article class="flex min-h-44 flex-col rounded-lg border border-zinc-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold {{ $item['complete'] ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-500' }}">
                                {{ $item['complete'] ? 'OK' : $loop->iteration }}
                            </span>
                            <flux:badge :color="$item['complete'] ? 'green' : 'amber'">{{ $item['complete'] ? 'Done' : 'Next' }}</flux:badge>
                        </div>

                        <h3 class="mt-4 text-sm font-semibold">{{ $item['label'] }}</h3>
                        <p class="mt-2 flex-1 text-xs leading-5 text-zinc-500">{{ $item['detail'] }}</p>
                        <p class="mt-3 text-xs leading-5 text-zinc-600">{{ $item['status'] }}</p>

                        <flux:button href="{{ route($item['route']) }}" variant="{{ $item['complete'] ? 'outline' : 'primary' }}" size="sm" class="mt-4">
                            {{ $item['action'] }}
                        </flux:button>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

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
            ['label' => 'Gross Sales', 'value' => 'NGN '.number_format($paidRevenue, 2), 'hint' => 'Successful customer payments'],
            ['label' => auth()->user()->isSuperAdmin() ? 'Platform Commission' : 'Platform Fees', 'value' => 'NGN '.number_format($platformCommission, 2), 'hint' => 'Commission deducted from sales'],
            ['label' => 'Tenant Net', 'value' => 'NGN '.number_format($tenantNetRevenue, 2), 'hint' => 'Successful sales after commission'],
            ['label' => 'Expenses', 'value' => 'NGN '.number_format($totalExpenses, 2), 'hint' => 'Recorded operating costs'],
            ['label' => 'Estimated Profit', 'value' => 'NGN '.number_format($estimatedProfit, 2), 'hint' => 'Tenant net sales minus expenses'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-3xl font-semibold">{{ is_numeric($stat['value']) ? number_format($stat['value']) : $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col justify-between gap-3 md:flex-row md:items-center">
            <div>
                <p class="text-sm font-medium text-zinc-500">This Month Finance</p>
                <h2 class="mt-1 text-xl font-semibold">{{ $monthFinanceSummary['period'] }} performance</h2>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button
                    href="{{ route('admin.reports.sales', ['from' => $monthFinanceSummary['from'], 'to' => $monthFinanceSummary['to']]) }}"
                    variant="outline"
                    size="sm"
                    icon="chart-bar"
                >
                    Sales report
                </flux:button>
                <flux:button
                    href="{{ route('admin.expenses.index', ['from' => $monthFinanceSummary['from'], 'to' => $monthFinanceSummary['to']]) }}"
                    variant="outline"
                    size="sm"
                    icon="receipt-percent"
                >
                    Expenses
                </flux:button>
            </div>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            @foreach ([
                ['label' => 'Gross Sales', 'value' => 'NGN '.number_format($monthFinanceSummary['gross_sales'], 2), 'hint' => 'Customer payments received this month'],
                ['label' => auth()->user()->isSuperAdmin() ? 'Commission' : 'Platform Fees', 'value' => 'NGN '.number_format($monthFinanceSummary['platform_commission'], 2), 'hint' => 'Platform commission deducted this month'],
                ['label' => 'Tenant Net', 'value' => 'NGN '.number_format($monthFinanceSummary['tenant_net'], 2), 'hint' => 'Sales retained by the tenant'],
                ['label' => 'Expenses', 'value' => 'NGN '.number_format($monthFinanceSummary['expenses'], 2), 'hint' => 'Costs recorded this month'],
                ['label' => 'Profit', 'value' => 'NGN '.number_format($monthFinanceSummary['profit'], 2), 'hint' => 'Tenant net sales minus expenses'],
                ['label' => 'Margin', 'value' => is_null($monthFinanceSummary['margin']) ? 'No sales' : $monthFinanceSummary['margin'].'%', 'hint' => 'Profit as a share of tenant net sales'],
            ] as $monthlyStat)
                <article class="rounded-lg border border-zinc-200 p-4">
                    <p class="text-sm font-medium text-zinc-500">{{ $monthlyStat['label'] }}</p>
                    <p class="mt-3 text-2xl font-semibold">{{ $monthlyStat['value'] }}</p>
                    <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $monthlyStat['hint'] }}</p>
                </article>
            @endforeach
        </div>
    </section>

    @if ($budgetWatch->isNotEmpty() || $budgetCategoryCount > 0)
        <section class="mt-6 rounded-lg border {{ $budgetWatch->isNotEmpty() ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }} p-5 shadow-sm">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-base font-semibold {{ $budgetWatch->isNotEmpty() ? 'text-amber-950' : 'text-emerald-950' }}">Budget Watch</h2>
                    <p class="mt-1 text-sm {{ $budgetWatch->isNotEmpty() ? 'text-amber-700' : 'text-emerald-700' }}">
                        @if ($budgetWatch->isNotEmpty())
                            Current-month expense categories at 80% or more of their monthly budget.
                        @else
                            {{ number_format($budgetCategoryCount) }} budgeted categories are currently below the 80% watch threshold.
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button
                        href="{{ route('admin.reports.sales', ['from' => $monthFinanceSummary['from'], 'to' => $monthFinanceSummary['to']]) }}"
                        variant="outline"
                        size="sm"
                        icon="chart-bar"
                    >
                        Review report
                    </flux:button>
                    <flux:button
                        href="{{ route('admin.expenses.index', ['from' => $monthFinanceSummary['from'], 'to' => $monthFinanceSummary['to']]) }}"
                        variant="outline"
                        size="sm"
                        icon="receipt-percent"
                    >
                        View expenses
                    </flux:button>
                </div>
            </div>

            @if ($budgetWatch->isEmpty())
                <div class="mt-5 rounded-lg border border-emerald-200 bg-white p-4">
                    <p class="text-sm font-medium text-emerald-950">All budgeted categories are under watch level.</p>
                    <p class="mt-1 text-sm text-emerald-700">The dashboard will highlight a category here once current-month spending reaches 80% of its monthly budget.</p>
                </div>
            @else
            <div class="mt-5 overflow-hidden rounded-lg border border-amber-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-amber-50 text-amber-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Category</th>
                            <th class="px-4 py-3 font-medium">Tenant</th>
                            <th class="px-4 py-3 text-right font-medium">Spent</th>
                            <th class="px-4 py-3 text-right font-medium">Budget</th>
                            <th class="px-4 py-3 text-right font-medium">Variance</th>
                            <th class="px-4 py-3 text-right font-medium">Usage</th>
                            <th class="px-4 py-3 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100">
                        @foreach ($budgetWatch as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $row['category'] }}</p>
                                    <p class="mt-1 text-xs {{ $row['usage'] > 100 ? 'text-red-700' : 'text-amber-700' }}">{{ $row['status'] }}</p>
                                </td>
                                <td class="px-4 py-3 text-zinc-600">{{ $row['tenant'] ?: 'All tenants' }}</td>
                                <td class="px-4 py-3 text-right font-semibold">NGN {{ number_format($row['spent'], 2) }}</td>
                                <td class="px-4 py-3 text-right">NGN {{ number_format($row['budget'], 2) }}</td>
                                <td class="px-4 py-3 text-right {{ $row['variance'] < 0 ? 'font-semibold text-red-700' : 'text-zinc-700' }}">
                                    NGN {{ number_format($row['variance'], 2) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="ml-auto flex w-28 flex-col items-end gap-1">
                                        <span class="{{ $row['usage'] > 100 ? 'font-semibold text-red-700' : 'text-zinc-700' }}">{{ $row['usage'] }}%</span>
                                        <span class="block h-1.5 w-full overflow-hidden rounded-full bg-amber-100">
                                            <span class="block h-full rounded-full {{ $row['usage'] > 100 ? 'bg-red-600' : 'bg-amber-500' }}" style="width: {{ min($row['usage'], 100) }}%"></span>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button
                                        href="{{ route('admin.expenses.index', ['from' => $monthFinanceSummary['from'], 'to' => $monthFinanceSummary['to'], 'category' => $row['category_id']]) }}"
                                        variant="outline"
                                        size="sm"
                                        icon="magnifying-glass"
                                    >
                                        Details
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </section>
    @endif

    @if ($tenantBillingSummary)
        <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <p class="text-sm font-medium text-zinc-500">Platform Plan</p>
                    <h2 class="mt-2 text-2xl font-semibold">{{ $tenantBillingSummary['plan_name'] }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ $tenantBillingSummary['price'] }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-medium text-zinc-700">{{ $tenantBillingSummary['status'] }}</span>
                    <span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">{{ $tenantBillingSummary['period_label'] }}</span>
                    <a href="{{ route('admin.billing.index') }}" class="rounded-md border border-zinc-200 px-3 py-1.5 text-sm font-medium hover:bg-zinc-50">Manage billing</a>
                </div>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                @foreach ($tenantBillingSummary['usage'] as $usage)
                    <div class="rounded-lg border border-zinc-200 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium">{{ $usage['label'] }}</p>
                            <p class="text-sm text-zinc-500">{{ number_format($usage['used']) }} / {{ $usage['limit_label'] }}</p>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-100">
                            <div
                                class="h-full rounded-full {{ $usage['is_limited'] && $usage['percent'] >= 90 ? 'bg-amber-500' : 'bg-zinc-950' }}"
                                style="width: {{ $usage['percent'] }}%"
                            ></div>
                        </div>
                        <p class="mt-2 text-xs leading-5 text-zinc-500">
                            {{ $usage['is_limited'] ? 'Upgrade before adding beyond this plan limit.' : 'No plan limit is applied to this resource.' }}
                        </p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($platformBillingSummary)
        <section class="mt-6 grid gap-4 md:grid-cols-4">
            @foreach ([
                ['label' => 'Billing Plans', 'value' => $platformBillingSummary['plan_count'], 'hint' => 'Plans tenant admins can subscribe to'],
                ['label' => 'Active Tenants', 'value' => $platformBillingSummary['active_subscription_count'], 'hint' => 'Active or trialing platform subscriptions'],
                ['label' => 'Past Due', 'value' => $platformBillingSummary['past_due_subscription_count'], 'hint' => 'Tenants needing billing attention'],
                ['label' => 'Platform MRR', 'value' => 'NGN '.number_format($platformBillingSummary['monthly_recurring_revenue'], 2), 'hint' => 'Active subscription amount per month'],
            ] as $billingStat)
                <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-zinc-500">{{ $billingStat['label'] }}</p>
                    <p class="mt-3 text-2xl font-semibold">{{ is_numeric($billingStat['value']) ? number_format($billingStat['value']) : $billingStat['value'] }}</p>
                    <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $billingStat['hint'] }}</p>
                </div>
            @endforeach
        </section>
    @endif

    @if ($overdueRecurringExpenses->isNotEmpty())
        <section class="mt-6 rounded-lg border border-red-200 bg-red-50 p-5 shadow-sm">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-base font-semibold text-red-950">Overdue Recurring Expenses</h2>
                    <p class="mt-1 text-sm text-red-700">These recurring costs have due dates before today.</p>
                </div>
                <flux:button href="{{ route('admin.expenses.index', ['schedule' => 'overdue']) }}" variant="outline" size="sm" icon="receipt-percent">Review overdue</flux:button>
            </div>

            <div class="mt-5 overflow-hidden rounded-lg border border-red-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-red-50 text-red-700">
                        <tr>
                            <th class="px-4 py-3 font-medium">Expense</th>
                            <th class="px-4 py-3 font-medium">Tenant</th>
                            <th class="px-4 py-3 font-medium">Due</th>
                            <th class="px-4 py-3 text-right font-medium">Amount</th>
                            <th class="px-4 py-3 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100">
                        @foreach ($overdueRecurringExpenses as $expense)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $expense->title }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ $expense->category?->name ?? 'Uncategorized' }}</p>
                                </td>
                                <td class="px-4 py-3 text-zinc-600">{{ $expense->tenant?->company_name }}</td>
                                <td class="px-4 py-3 text-red-700">{{ $expense->next_due_on?->toFormattedDateString() }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ $expense->currency }} {{ number_format($expense->amount, 2) }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('admin.expenses.record-recurring', $expense) }}" class="flex justify-end">
                                        @csrf
                                        <flux:button type="submit" variant="primary" size="sm" icon="check">Record</flux:button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
                <h2 class="text-base font-semibold">Upcoming Recurring Expenses</h2>
                <p class="mt-1 text-sm text-zinc-500">Costs due within the next 30 days.</p>
            </div>
            <flux:button href="{{ route('admin.expenses.index', ['schedule' => 'due_soon']) }}" variant="outline" size="sm" icon="receipt-percent">View due soon</flux:button>
        </div>

        <div class="mt-5 overflow-hidden rounded-lg border border-zinc-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Expense</th>
                        <th class="px-4 py-3 font-medium">Tenant</th>
                        <th class="px-4 py-3 font-medium">Frequency</th>
                        <th class="px-4 py-3 font-medium">Due</th>
                        <th class="px-4 py-3 text-right font-medium">Amount</th>
                        <th class="px-4 py-3 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($upcomingRecurringExpenses as $expense)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $expense->title }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $expense->category?->name ?? 'Uncategorized' }}</p>
                            </td>
                            <td class="px-4 py-3 text-zinc-600">{{ $expense->tenant?->company_name }}</td>
                            <td class="px-4 py-3 text-zinc-600">{{ $expense->recurring_frequency ? str($expense->recurring_frequency)->title() : 'Not set' }}</td>
                            <td class="px-4 py-3 text-zinc-600">{{ $expense->next_due_on?->toFormattedDateString() }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ $expense->currency }} {{ number_format($expense->amount, 2) }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('admin.expenses.record-recurring', $expense) }}" class="flex justify-end">
                                    @csrf
                                    <flux:button type="submit" variant="outline" size="sm" icon="check">Record</flux:button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No recurring expenses are due in the next 30 days.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
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
