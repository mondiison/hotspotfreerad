<x-layouts.admin title="Sales Report" heading="Sales Report" subheading="Successful hotspot sales by date range and period.">
    <form method="GET" class="grid gap-4 rounded-lg border border-zinc-200 bg-white p-5 md:grid-cols-[1fr_1fr_180px_auto]">
        <flux:field>
            <flux:label>From</flux:label>
            <flux:input type="date" name="from" value="{{ $filters['from'] }}" />
            <flux:error name="from" />
        </flux:field>

        <flux:field>
            <flux:label>To</flux:label>
            <flux:input type="date" name="to" value="{{ $filters['to'] }}" />
            <flux:error name="to" />
        </flux:field>

        <flux:field>
            <flux:label>Group by</flux:label>
            <flux:select name="group">
                <flux:select.option value="day" :selected="$filters['group'] === 'day'">Day</flux:select.option>
                <flux:select.option value="month" :selected="$filters['group'] === 'month'">Month</flux:select.option>
                <flux:select.option value="year" :selected="$filters['group'] === 'year'">Year</flux:select.option>
            </flux:select>
        </flux:field>

        <div class="flex items-end">
            <flux:button type="submit" variant="primary" icon="funnel" class="w-full">Apply</flux:button>
        </div>
    </form>

    <section class="mt-6 grid gap-4 md:grid-cols-3 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Sales', 'value' => number_format($summary['sales_count']), 'hint' => 'Successful payments in range'],
            ['label' => 'Gross Sales', 'value' => 'NGN '.number_format($summary['revenue'], 2), 'hint' => 'Total customer payments'],
            ['label' => 'Platform Commission', 'value' => 'NGN '.number_format($summary['platform_fee'], 2), 'hint' => 'Platform share from commission tenants'],
            ['label' => 'Tenant Net', 'value' => 'NGN '.number_format($summary['tenant_net'], 2), 'hint' => 'Amount retained by tenants'],
            ['label' => 'Expenses', 'value' => 'NGN '.number_format($summary['expenses'], 2), 'hint' => 'Operating costs in range'],
            ['label' => 'Estimated Profit', 'value' => 'NGN '.number_format($summary['estimated_profit'], 2), 'hint' => 'Tenant net minus expenses'],
            ['label' => 'Average Sale', 'value' => 'NGN '.number_format($summary['average_sale'], 2), 'hint' => 'Gross sales divided by sales'],
            ['label' => 'Periods', 'value' => number_format($summary['period_count']), 'hint' => 'Grouped rows below'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 p-5">
                <h2 class="text-base font-semibold">Sales by {{ ucfirst($filters['group']) }}</h2>
                <p class="mt-1 text-sm text-zinc-500">Only successful payments are counted.</p>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Period</th>
                        <th class="px-4 py-3 text-right font-medium">Sales</th>
                        <th class="px-4 py-3 text-right font-medium">Average</th>
                        <th class="px-4 py-3 text-right font-medium">Gross</th>
                        <th class="px-4 py-3 text-right font-medium">Commission</th>
                        <th class="px-4 py-3 text-right font-medium">Tenant Net</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $row['period'] }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row['sales_count']) }}</td>
                            <td class="px-4 py-3 text-right">NGN {{ number_format($row['average_sale'], 2) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">NGN {{ number_format($row['revenue'], 2) }}</td>
                            <td class="px-4 py-3 text-right">NGN {{ number_format($row['platform_fee'], 2) }}</td>
                            <td class="px-4 py-3 text-right">NGN {{ number_format($row['tenant_net'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No successful sales in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="grid gap-6">
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 p-5">
                <h2 class="text-base font-semibold">Sales by Shop</h2>
                <p class="mt-1 text-sm text-zinc-500">Best performing hotspot locations in the selected range.</p>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Shop</th>
                        <th class="px-4 py-3 text-right font-medium">Sales</th>
                        <th class="px-4 py-3 text-right font-medium">Gross</th>
                        <th class="px-4 py-3 text-right font-medium">Commission</th>
                        <th class="px-4 py-3 text-right font-medium">Tenant Net</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($shopRows as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $row['shop'] }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row['sales_count']) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">NGN {{ number_format($row['revenue'], 2) }}</td>
                            <td class="px-4 py-3 text-right">NGN {{ number_format($row['platform_fee'], 2) }}</td>
                            <td class="px-4 py-3 text-right">NGN {{ number_format($row['tenant_net'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No shop sales in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 p-5">
                    <h2 class="text-base font-semibold">Expenses by Category</h2>
                    <p class="mt-1 text-sm text-zinc-500">Costs recorded in the selected report range.</p>
                </div>

                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Category</th>
                            <th class="px-4 py-3 text-right font-medium">Count</th>
                            <th class="px-4 py-3 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($expenseRows as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $row['category'] }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($row['count']) }}</td>
                                <td class="px-4 py-3 text-right font-semibold">NGN {{ number_format($row['amount'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-zinc-500">No expenses in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</x-layouts.admin>
