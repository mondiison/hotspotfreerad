<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif
        </div>

        @if ($tab === 'expenses')
            <div class="flex flex-wrap gap-2">
                <flux:button href="{{ $this->exportUrl() }}" variant="outline" icon="arrow-down-tray">Export CSV</flux:button>
                <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
                    Add Expense
                </flux:button>
            </div>
        @endif
    </div>

    <div class="mb-6 flex flex-wrap gap-2 rounded-lg border border-zinc-200 bg-white p-2 shadow-sm">
        <flux:button type="button" wire:click="showExpenses" variant="{{ $tab === 'expenses' ? 'primary' : 'ghost' }}" icon="receipt-percent">
            Expenses
        </flux:button>
        <flux:button type="button" wire:click="showCategories" variant="{{ $tab === 'categories' ? 'primary' : 'ghost' }}" icon="tag">
            Categories
        </flux:button>
    </div>

    @if ($tab === 'categories')
        <livewire:admin.expense-categories-index :filters="request()->only(['search', 'scope', 'status', 'budget'])" />
    @else
        <section class="grid gap-4 md:grid-cols-4">
            @foreach ([
                ['label' => 'Expenses', 'value' => number_format($summary['count']), 'hint' => 'Matching current filters'],
                ['label' => 'Total Spent', 'value' => 'NGN '.number_format($summary['total'], 2), 'hint' => 'All recorded expenses'],
                ['label' => 'Budget', 'value' => $summary['budget'] > 0 ? 'NGN '.number_format($summary['budget'], 2) : 'No budget', 'hint' => 'Prorated budget for used categories'],
                ['label' => 'Remaining', 'value' => is_null($summary['budget_variance']) ? 'No budget' : 'NGN '.number_format($summary['budget_variance'], 2), 'hint' => 'Budget minus filtered spend'],
                ['label' => 'Budget Usage', 'value' => is_null($summary['budget_usage']) ? 'No budget' : $summary['budget_usage'].'%', 'hint' => 'Filtered spend against budget'],
                ['label' => 'Recurring', 'value' => 'NGN '.number_format($summary['recurring'], 2), 'hint' => 'Marked as recurring'],
                ['label' => 'Overdue', 'value' => number_format($summary['overdue_count']), 'hint' => 'Recurring schedules past due'],
                ['label' => 'Categories', 'value' => number_format($summary['category_count']), 'hint' => 'Expense groups used'],
            ] as $stat)
                <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                    <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                    <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
                </div>
            @endforeach
        </section>

        <section class="mt-6 flex flex-wrap gap-2">
            @foreach ($presets as $key => $label)
                <flux:button type="button" wire:click="setPreset('{{ $key }}')" variant="{{ $preset === $key ? 'primary' : 'outline' }}" size="sm">
                    {{ $label }}
                </flux:button>
            @endforeach

            <flux:button type="button" wire:click="customRange" variant="{{ $preset ? 'outline' : 'primary' }}" size="sm">
                Custom
            </flux:button>
        </section>

        <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[1fr_1fr_190px_190px_1fr_auto]">
                <flux:input type="date" wire:model.live="from" />
                <flux:input type="date" wire:model.live="to" />
                <flux:select wire:model.live="category">
                    <flux:select.option value="">All categories</flux:select.option>
                    @foreach ($categories as $filterCategory)
                        <flux:select.option value="{{ $filterCategory->id }}">{{ $filterCategory->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="schedule">
                    <flux:select.option value="">All schedules</flux:select.option>
                    <flux:select.option value="recurring">Recurring</flux:select.option>
                    <flux:select.option value="due_soon">Due soon</flux:select.option>
                    <flux:select.option value="overdue">Overdue</flux:select.option>
                </flux:select>
                <flux:input wire:model.live.debounce.350ms="search" placeholder="Search title, vendor, note" />
                <flux:button type="button" variant="outline" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,from,to,category,schedule,search">Reset</flux:button>
            </div>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                <table class="min-w-[820px] w-full text-left text-sm">
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
                            <tr wire:key="expense-{{ $expense->id }}">
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $expense->title }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ $expense->incurred_on->toFormattedDateString() }}{{ $expense->vendor ? ' - '.$expense->vendor : '' }}</p>
                                    @if ($expense->is_recurring)
                                        <p class="mt-1 text-xs font-medium text-blue-700">Recurring</p>
                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $expense->recurring_frequency ? str($expense->recurring_frequency)->title() : 'Frequency not set' }}
                                            @if ($expense->next_due_on)
                                                - due {{ $expense->next_due_on->toFormattedDateString() }}
                                            @endif
                                        </p>
                                    @endif
                                    @if ($expense->receipt_path)
                                        <a href="{{ route('admin.expenses.receipt', $expense) }}" class="mt-1 inline-flex text-xs font-medium text-zinc-950 underline decoration-zinc-300 underline-offset-4">Receipt attached</a>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $expense->category?->name ?? 'Uncategorized' }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $expense->tenant?->company_name }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ $expense->currency }} {{ number_format($expense->amount, 2) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        @if ($expense->is_recurring && $expense->next_due_on)
                                            <flux:button type="button" variant="outline" size="sm" icon="check" wire:click="recordRecurring({{ $expense->id }})" wire:loading.attr="disabled" wire:target="recordRecurring({{ $expense->id }})">Record</flux:button>
                                        @endif
                                        <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $expense->id }})" wire:loading.attr="disabled" wire:target="edit({{ $expense->id }})">Edit</flux:button>
                                        <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $expense->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $expense->id }})">Delete</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center">
                                    <p class="font-medium">No expenses match this view.</p>
                                    <p class="mt-1 text-sm text-zinc-500">Record operating costs like subscriptions, maintenance, equipment, personnel, rent, and utilities.</p>
                                    <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add Expense</flux:button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 p-5">
                    <h2 class="text-base font-semibold">Spend by Category</h2>
                    <p class="mt-1 text-sm text-zinc-500">Largest operating costs in the selected range.</p>
                </div>
                <div class="overflow-x-auto overflow-y-hidden">
                <table class="min-w-[720px] w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Category</th>
                            <th class="px-4 py-3 text-right font-medium">Count</th>
                            <th class="px-4 py-3 text-right font-medium">Amount</th>
                            <th class="px-4 py-3 text-right font-medium">Budget</th>
                            <th class="px-4 py-3 text-right font-medium">Variance</th>
                            <th class="px-4 py-3 text-right font-medium">Usage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($categoryRows as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $row['category'] }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($row['count']) }}</td>
                                <td class="px-4 py-3 text-right font-semibold">NGN {{ number_format($row['amount'], 2) }}</td>
                                <td class="px-4 py-3 text-right">{{ is_null($row['budget']) ? 'No budget' : 'NGN '.number_format($row['budget'], 2) }}</td>
                                <td class="px-4 py-3 text-right {{ ! is_null($row['variance']) && $row['variance'] < 0 ? 'font-semibold text-red-700' : 'text-zinc-700' }}">
                                    {{ is_null($row['variance']) ? 'No budget' : 'NGN '.number_format($row['variance'], 2) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if (is_null($row['usage']))
                                        <span class="text-zinc-500">No budget</span>
                                    @else
                                        <span class="{{ $row['usage'] > 100 ? 'font-semibold text-red-700' : 'text-zinc-700' }}">{{ $row['usage'] }}%</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No category spend yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </section>

        <div class="mt-4">{{ $expenses->links() }}</div>
    @endif

    <flux:modal wire:model.self="showFormModal" class="md:w-4xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingExpenseId ? 'Edit Expense' : 'Add Expense' }}</flux:heading>
                <flux:text class="mt-2">Record business costs that affect tenant profitability and dashboard reporting.</flux:text>
            </div>

            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    @if (auth()->user()->isSuperAdmin())
                        <flux:field>
                            <flux:label>Tenant</flux:label>
                            <flux:select wire:model.live="tenant_id" required>
                                <flux:select.option value="">Select tenant</flux:select.option>
                                @foreach ($tenants as $tenant)
                                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->company_name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="tenant_id" />
                        </flux:field>
                    @endif

                    <flux:field>
                        <flux:label>Category</flux:label>
                        <flux:select wire:model.live="expense_category_id">
                            <flux:select.option value="">Uncategorized</flux:select.option>
                            @foreach ($formCategories as $formCategory)
                                <flux:select.option value="{{ $formCategory->id }}">{{ $formCategory->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>Examples: subscription, personnel, maintenance, equipment, rent and utilities.</flux:description>
                        <flux:error name="expense_category_id" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>Expense title</flux:label>
                        <flux:input wire:model.blur="title" icon="receipt-percent" placeholder="Router replacement, monthly ISP bill, installer payment" required />
                        <flux:error name="title" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Amount</flux:label>
                        <flux:input type="number" wire:model.blur="amount" min="0.01" step="0.01" icon="banknotes" required />
                        <flux:error name="amount" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Currency</flux:label>
                        <flux:input wire:model.blur="currency" maxlength="3" required />
                        <flux:error name="currency" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Date incurred</flux:label>
                        <flux:input type="date" wire:model.blur="incurred_on" required />
                        <flux:error name="incurred_on" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Vendor or payee</flux:label>
                        <flux:input wire:model.blur="vendor" icon="building-storefront" placeholder="ISP provider, technician, staff name" />
                        <flux:error name="vendor" />
                    </flux:field>

                    <div class="md:col-span-2">
                        <flux:checkbox wire:model.live="is_recurring" label="Recurring expense" />
                    </div>

                    <flux:field>
                        <flux:label>Recurring frequency</flux:label>
                        <flux:select wire:model.live="recurring_frequency" :disabled="! $is_recurring">
                            <flux:select.option value="">Not scheduled</flux:select.option>
                            @foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>Use this for rent, salaries, upstream internet, generator service, and other repeat costs.</flux:description>
                        <flux:error name="recurring_frequency" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Next due date</flux:label>
                        <flux:input type="date" wire:model.blur="next_due_on" :disabled="! $is_recurring" />
                        <flux:description>Shows on the dashboard when due within the next 30 days.</flux:description>
                        <flux:error name="next_due_on" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>Receipt</flux:label>
                        <flux:input type="file" wire:model="receipt" accept=".jpg,.jpeg,.png,.pdf,.webp" />
                        <flux:description>Optional receipt or invoice. Accepted formats: JPG, PNG, WEBP, PDF. Maximum size: 4 MB.</flux:description>
                        <flux:error name="receipt" />
                    </flux:field>

                    @if ($editingExpense?->receipt_path)
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 md:col-span-2">
                            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                <div>
                                    <p class="text-sm font-medium">Receipt attached</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ basename($editingExpense->receipt_path) }}</p>
                                </div>
                                <flux:button href="{{ route('admin.expenses.receipt', $editingExpense) }}" variant="outline" icon="arrow-down-tray">Download</flux:button>
                            </div>
                            <div class="mt-3">
                                <flux:checkbox wire:model.live="remove_receipt" label="Remove current receipt" />
                            </div>
                        </div>
                    @endif

                    <flux:field class="md:col-span-2">
                        <flux:label>Notes</flux:label>
                        <flux:textarea wire:model.blur="notes" rows="4" placeholder="Optional context, receipt reference, location, or reason for the expense." />
                        <flux:error name="notes" />
                    </flux:field>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <h2 class="text-sm font-semibold text-zinc-950">Expense guide</h2>
                    <p class="mt-1 text-sm leading-6 text-zinc-600">
                        Use recurring schedules for predictable monthly costs. Use categories and budgets to make profit reports and dashboard warnings more useful.
                    </p>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save,receipt">
                        <span wire:loading.remove wire:target="save,receipt">Save Expense</span>
                        <span wire:loading wire:target="save,receipt">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Expense</flux:heading>
                <flux:text class="mt-2">This removes the cost record and any attached receipt file.</flux:text>
            </div>

            @if ($deletingExpense)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingExpense->title }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $deletingExpense->currency }} {{ number_format($deletingExpense->amount, 2) }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Expense</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
