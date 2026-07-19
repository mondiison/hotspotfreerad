<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            Add Category
        </flux:button>
    </div>

    <section class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 md:grid-cols-[1fr_160px_160px_170px_auto]">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search category or description" />
            <flux:select wire:model.live="scope">
                <flux:select.option value="">All scopes</flux:select.option>
                <flux:select.option value="platform">Platform</flux:select.option>
                <flux:select.option value="tenant">Tenant custom</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="status">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="budget">
                <flux:select.option value="">All budgets</flux:select.option>
                <flux:select.option value="budgeted">Budgeted</flux:select.option>
                <flux:select.option value="unbudgeted">No budget</flux:select.option>
            </flux:select>
            <flux:button type="button" variant="outline" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,scope,status,budget">
                Reset
            </flux:button>
        </div>
    </section>

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
                    <tr wire:key="expense-category-{{ $category->id }}">
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
                                    <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $category->id }})" wire:loading.attr="disabled" wire:target="edit({{ $category->id }})">Edit</flux:button>
                                    <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $category->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $category->id }})">Delete</flux:button>
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
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add Category</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $categories->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-2xl" :dismissible="true">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingCategoryId ? 'Edit Expense Category' : 'Add Expense Category' }}</flux:heading>
                <flux:text class="mt-2">Categories make expense and profit reports easier to understand.</flux:text>
            </div>

            <form wire:submit.prevent="save" class="space-y-5">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model.blur="name" icon="tag" placeholder="Fuel, Internet subscription, Staff salary" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model.blur="description" rows="4" placeholder="Explain when this category should be used." />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>Monthly budget</flux:label>
                    <flux:input type="number" wire:model.blur="monthly_budget" min="0" step="0.01" icon="banknotes" placeholder="Example: 50000" />
                    <flux:description>Optional planning target for this category. Leave empty for no budget limit.</flux:description>
                    <flux:error name="monthly_budget" />
                </flux:field>

                <flux:checkbox wire:model.live="is_active" label="Active category" />

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                    @if (auth()->user()->isSuperAdmin())
                        Super admin categories become platform defaults available to every tenant.
                    @else
                        Tenant categories are private to your workspace and appear beside platform defaults.
                    @endif
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Category</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Category</flux:heading>
                <flux:text class="mt-2">This removes the category only if no expenses are using it.</flux:text>
            </div>

            @if ($deleteError)
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ $deleteError }}
                </div>
            @endif

            @if ($deletingCategory)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingCategory->name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $deletingCategory->description ?: 'No description' }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Category</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
