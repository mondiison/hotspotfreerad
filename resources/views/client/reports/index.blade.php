<x-layouts.client title="Reports">
    <h1 class="mb-1 text-xl font-semibold">Reports</h1>
    <p class="mb-5 text-sm text-zinc-500">Sales across your shop(s).</p>

    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($presets as $value => $label)
            <a
                href="{{ route('client.reports.index', ['preset' => $value]) }}"
                class="rounded-md border px-3 py-1.5 text-sm {{ $filters['preset'] === $value ? 'border-zinc-950 bg-zinc-950 text-white' : 'border-zinc-200 bg-white text-zinc-700' }}"
            >{{ $label }}</a>
        @endforeach
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 bg-white p-4">
            <p class="text-xs font-medium uppercase text-zinc-500">Sales</p>
            <p class="mt-2 text-2xl font-semibold">{{ number_format($summary['sales_count']) }}</p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4">
            <p class="text-xs font-medium uppercase text-zinc-500">Revenue</p>
            <p class="mt-2 text-2xl font-semibold">NGN {{ number_format($summary['revenue'], 2) }}</p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4">
            <p class="text-xs font-medium uppercase text-zinc-500">Average sale</p>
            <p class="mt-2 text-2xl font-semibold">NGN {{ number_format($summary['average_sale'], 2) }}</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <h2 class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-semibold">By shop</h2>
            <table class="w-full text-left text-sm">
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($shopRows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row['shop'] }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row['sales_count']) }} sale(s)</td>
                            <td class="px-4 py-3 text-right font-medium">NGN {{ number_format($row['revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-zinc-500">No sales in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <h2 class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-semibold">By package</h2>
            <table class="w-full text-left text-sm">
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($packageRows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row['package'] }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row['sales_count']) }} sale(s)</td>
                            <td class="px-4 py-3 text-right font-medium">NGN {{ number_format($row['revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-zinc-500">No sales in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.client>
