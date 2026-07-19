<x-layouts.admin
    :title="$category->exists ? 'Edit Expense Category' : 'Add Expense Category'"
    :heading="$category->exists ? 'Edit Expense Category' : 'Add Expense Category'"
    subheading="Categories make expense and profit reports easier to understand."
>
    <form method="POST" action="{{ $category->exists ? route('admin.expense-categories.update', $category) : route('admin.expense-categories.store') }}" class="max-w-3xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($category->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5">
            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input name="name" value="{{ old('name', $category->name) }}" icon="tag" placeholder="Fuel, Internet subscription, Staff salary" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:textarea name="description" rows="4" placeholder="Explain when this category should be used.">{{ old('description', $category->description) }}</flux:textarea>
                <flux:error name="description" />
            </flux:field>

            <flux:field>
                <flux:label>Monthly budget</flux:label>
                <flux:input
                    type="number"
                    name="monthly_budget"
                    value="{{ old('monthly_budget', $category->monthly_budget) }}"
                    min="0"
                    step="0.01"
                    icon="banknotes"
                    placeholder="Example: 50000"
                />
                <flux:description>Optional planning target for this category. Leave empty for no budget limit.</flux:description>
                <flux:error name="monthly_budget" />
            </flux:field>

            <flux:checkbox name="is_active" value="1" :checked="(bool) old('is_active', $category->is_active ?? true)" label="Active category" />

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                @if (auth()->user()->isSuperAdmin())
                    Super admin categories become platform defaults available to every tenant.
                @else
                    Tenant categories are private to your workspace and appear beside platform defaults.
                @endif
            </div>
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save Category</flux:button>
            <flux:button href="{{ route('admin.expense-categories.index') }}" variant="outline">Cancel</flux:button>
        </div>
    </form>
</x-layouts.admin>
