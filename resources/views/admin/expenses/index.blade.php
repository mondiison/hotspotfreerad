<x-layouts.admin title="Expenses" heading="Expenses" subheading="Track operating costs so sales reports can show practical profit.">
    <x-slot:action>
        <div class="flex gap-2">
            <flux:button href="{{ route('admin.expense-categories.index') }}" variant="outline" icon="tag">Categories</flux:button>
            <flux:button href="{{ route('admin.expenses.create') }}" variant="primary" icon="plus">Add Expense</flux:button>
        </div>
    </x-slot:action>

    <section class="grid gap-4 md:grid-cols-4">
        @foreach ([
            ['label' => 'Expenses', 'value' => number_format($summary['count']), 'hint' => 'Matching current filters'],
            ['label' => 'Total Spent', 'value' => 'NGN '.number_format($summary['total'], 2), 'hint' => 'All recorded expenses'],
            ['label' => 'Recurring', 'value' => 'NGN '.number_format($summary['recurring'], 2), 'hint' => 'Marked as recurring'],
            ['label' => 'Categories', 'value' => number_format($summary['category_count']), 'hint' => 'Expense groups used'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <form method="GET" class="mt-6 grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 md:grid-cols-[1fr_1fr_190px_1fr_auto]">
        <flux:input type="date" name="from" value="{{ $filters['from'] }}" />
        <flux:input type="date" name="to" value="{{ $filters['to'] }}" />
        <select name="category" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) $filters['category'] === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <flux:input name="search" value="{{ $filters['search'] }}" placeholder="Search title, vendor, note" />
        <flux:button type="submit" variant="primary" icon="funnel">Filter</flux:button>
    </form>

    <section class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Expense</th>
                        <th class="px-4 py-3 font-medium">Category</th>
                        <th class="px-4 py-3 font-medium">Tenant</th>
                        <th class="px-4 py-3 text-right font-medium">Amount</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($expenses as $expense)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $expense->title }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $expense->incurred_on->toFormattedDateString() }}{{ $expense->vendor ? ' - '.$expense->vendor : '' }}</p>
                                @if ($expense->is_recurring)
                                    <p class="mt-1 text-xs font-medium text-blue-700">Recurring</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $expense->category?->name ?? 'Uncategorized' }}</td>
                            <td class="px-4 py-3 text-zinc-600">{{ $expense->tenant?->company_name }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ $expense->currency }} {{ number_format($expense->amount, 2) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('admin.expenses.edit', $expense) }}" variant="outline" size="sm" icon="pencil-square">Edit</flux:button>
                                    <form method="POST" action="{{ route('admin.expenses.destroy', $expense) }}" onsubmit="return confirm('Delete this expense?')">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" variant="danger" size="sm" icon="trash">Delete</flux:button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No expenses found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 p-5">
                <h2 class="text-base font-semibold">Spend by Category</h2>
                <p class="mt-1 text-sm text-zinc-500">Largest operating costs in the selected range.</p>
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
                    @forelse ($categoryRows as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $row['category'] }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row['count']) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">NGN {{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-zinc-500">No category spend yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $expenses->links() }}</div>
</x-layouts.admin>
