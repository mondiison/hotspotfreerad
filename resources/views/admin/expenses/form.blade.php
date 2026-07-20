<x-layouts.admin
    :title="$expense->exists ? 'Edit Expense' : 'Add Expense'"
    :heading="$expense->exists ? 'Edit Expense' : 'Add Expense'"
    subheading="Record business costs that affect tenant profitability."
>
    <form method="POST" action="{{ $expense->exists ? route('admin.expenses.update', $expense) : route('admin.expenses.store') }}" enctype="multipart/form-data" class="max-w-4xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($expense->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            @if (auth()->user()->isSuperAdmin())
                <flux:field>
                    <flux:label>Tenant</flux:label>
                    <flux:select name="tenant_id" required>
                        <flux:select.option value="">Select tenant</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}" :selected="(string) old('tenant_id', $expense->tenant_id) === (string) $tenant->id">{{ $tenant->company_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="tenant_id" />
                </flux:field>
            @endif

            <flux:field>
                <flux:label>Category</flux:label>
                <flux:select name="expense_category_id">
                    <flux:select.option value="">Uncategorized</flux:select.option>
                    @foreach ($categories as $category)
                        <flux:select.option value="{{ $category->id }}" :selected="(string) old('expense_category_id', $expense->expense_category_id) === (string) $category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>Examples: subscription, personnel, maintenance, equipment, rent and utilities.</flux:description>
                <flux:error name="expense_category_id" />
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label>Expense title</flux:label>
                <flux:input name="title" value="{{ old('title', $expense->title) }}" icon="receipt-percent" placeholder="Router replacement, monthly ISP bill, installer payment" required />
                <flux:error name="title" />
            </flux:field>

            <flux:field>
                <flux:label>Amount</flux:label>
                <flux:input type="number" name="amount" value="{{ old('amount', $expense->amount) }}" min="0.01" step="0.01" icon="banknotes" required />
                <flux:error name="amount" />
            </flux:field>

            <flux:field>
                <flux:label>Currency</flux:label>
                <flux:input name="currency" value="{{ old('currency', $expense->currency ?? 'NGN') }}" maxlength="3" required />
                <flux:error name="currency" />
            </flux:field>

            <flux:field>
                <flux:label>Date incurred</flux:label>
                <flux:input type="date" name="incurred_on" value="{{ old('incurred_on', optional($expense->incurred_on)->toDateString() ?? now()->toDateString()) }}" required />
                <flux:error name="incurred_on" />
            </flux:field>

            <flux:field>
                <flux:label>Vendor or payee</flux:label>
                <flux:input name="vendor" value="{{ old('vendor', $expense->vendor) }}" icon="building-storefront" placeholder="ISP provider, technician, staff name" />
                <flux:error name="vendor" />
            </flux:field>

            <div class="md:col-span-2">
                <flux:checkbox name="is_recurring" value="1" :checked="(bool) old('is_recurring', $expense->is_recurring)" label="Recurring expense" />
            </div>

            <flux:field>
                <flux:label>Recurring frequency</flux:label>
                <flux:select name="recurring_frequency">
                    <flux:select.option value="">Not scheduled</flux:select.option>
                    @foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $value => $label)
                        <flux:select.option value="{{ $value }}" :selected="old('recurring_frequency', $expense->recurring_frequency) === $value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>Use this for rent, salaries, internet upstream, generator service, and other repeat costs.</flux:description>
                <flux:error name="recurring_frequency" />
            </flux:field>

            <flux:field>
                <flux:label>Next due date</flux:label>
                <flux:input type="date" name="next_due_on" value="{{ old('next_due_on', optional($expense->next_due_on)->toDateString()) }}" />
                <flux:description>Shows on the dashboard when due within the next 30 days.</flux:description>
                <flux:error name="next_due_on" />
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label>Receipt</flux:label>
                <flux:input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf,.webp" />
                <flux:description>Optional receipt or invoice. Accepted formats: JPG, PNG, WEBP, PDF. Maximum size: 4 MB.</flux:description>
                <flux:error name="receipt" />
            </flux:field>

            @if ($expense->receipt_path)
                <div class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                        <div>
                            <p class="text-sm font-medium">Receipt attached</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ basename($expense->receipt_path) }}</p>
                        </div>
                        <flux:button href="{{ route('admin.expenses.receipt', $expense) }}" variant="outline" icon="arrow-down-tray">Download</flux:button>
                    </div>
                    <div class="mt-3">
                        <flux:checkbox name="remove_receipt" value="1" label="Remove current receipt" />
                    </div>
                </div>
            @endif

            <flux:field class="md:col-span-2">
                <flux:label>Notes</flux:label>
                <flux:textarea name="notes" rows="4" placeholder="Optional context, receipt reference, location, or reason for the expense.">{{ old('notes', $expense->notes) }}</flux:textarea>
                <flux:error name="notes" />
            </flux:field>
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save Expense</flux:button>
            <flux:button href="{{ route('admin.expenses.index') }}" wire:navigate variant="outline">Cancel</flux:button>
        </div>
    </form>
</x-layouts.admin>
