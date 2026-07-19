<x-layouts.admin title="Expense Categories" heading="Expense Categories" subheading="Group expenses into useful operating cost buckets.">
    <x-slot:action>
        <flux:button href="{{ route('admin.expense-categories.create') }}" variant="primary" icon="plus">Add Category</flux:button>
    </x-slot:action>

    <form method="GET" class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 md:grid-cols-[1fr_160px_160px_170px_auto]">
            <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search category or description" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <select name="scope" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
                <option value="">All scopes</option>
                <option value="platform" @selected(($filters['scope'] ?? '') === 'platform')>Platform</option>
                <option value="tenant" @selected(($filters['scope'] ?? '') === 'tenant')>Tenant custom</option>
            </select>
            <select name="status" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
                <option value="">All statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
            <select name="budget" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
                <option value="">All budgets</option>
                <option value="budgeted" @selected(($filters['budget'] ?? '') === 'budgeted')>Budgeted</option>
                <option value="unbudgeted" @selected(($filters['budget'] ?? '') === 'unbudgeted')>No budget</option>
            </select>
            <div class="flex gap-2">
                <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Filter</button>
                <a href="{{ route('admin.expense-categories.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Reset</a>
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Category</th>
                    <th class="px-4 py-3 font-medium">Scope</th>
                    <th class="px-4 py-3 text-right font-medium">Monthly Budget</th>
                    <th class="px-4 py-3 text-right font-medium">This Month</th>
                    <th class="px-4 py-3 text-right font-medium">Usage</th>
                    <th class="px-4 py-3 text-right font-medium">Expenses</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($categories as $category)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $category->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $category->description ?: 'No description' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            @if ($category->tenant_id)
                                <flux:badge color="green">Tenant custom</flux:badge>
                            @else
                                <flux:badge color="blue">Platform default</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($category->monthly_budget)
                                NGN {{ number_format((float) $category->monthly_budget, 2) }}
                            @else
                                <span class="text-zinc-500">No budget</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold">NGN {{ number_format((float) ($category->current_month_spent ?? 0), 2) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($category->monthly_budget)
                                @php($usage = round((((float) ($category->current_month_spent ?? 0)) / (float) $category->monthly_budget) * 100, 1))
                                <div class="ml-auto flex w-28 flex-col items-end gap-1">
                                    <span class="{{ $usage > 100 ? 'font-semibold text-red-700' : 'text-zinc-700' }}">{{ $usage }}%</span>
                                    <span class="block h-1.5 w-full overflow-hidden rounded-full bg-zinc-100">
                                        <span class="block h-full rounded-full {{ $usage >= 80 ? ($usage > 100 ? 'bg-red-600' : 'bg-amber-500') : 'bg-zinc-950' }}" style="width: {{ min($usage, 100) }}%"></span>
                                    </span>
                                </div>
                            @else
                                <span class="text-zinc-500">No budget</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ number_format($category->expenses_count) }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$category->is_active ? 'green' : 'zinc'">{{ $category->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            @php($canManage = auth()->user()->isSuperAdmin() || $category->tenant_id === auth()->user()->tenant_id)
                            @if ($canManage)
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('admin.expense-categories.edit', $category) }}" variant="outline" size="sm" icon="pencil-square">Edit</flux:button>
                                    <form method="POST" action="{{ route('admin.expense-categories.destroy', $category) }}" onsubmit="return confirm('Delete this category?')">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" variant="danger" size="sm" icon="trash">Delete</flux:button>
                                    </form>
                                </div>
                            @else
                                <p class="text-right text-xs text-zinc-500">Read only</p>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center">
                            <p class="font-medium">No expense categories match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Adjust the filters or create a category for a new operating cost bucket.</p>
                            <flux:button href="{{ route('admin.expense-categories.create') }}" variant="primary" icon="plus" class="mt-4">Add Category</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $categories->links() }}</div>
</x-layouts.admin>
