<x-layouts.admin title="Expense Categories" heading="Expense Categories" subheading="Group expenses into useful operating cost buckets.">
    <x-slot:action>
        <flux:button href="{{ route('admin.expense-categories.create') }}" variant="primary" icon="plus">Add Category</flux:button>
    </x-slot:action>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Category</th>
                    <th class="px-4 py-3 font-medium">Scope</th>
                    <th class="px-4 py-3 text-right font-medium">Monthly Budget</th>
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
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No expense categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $categories->links() }}</div>
</x-layouts.admin>
