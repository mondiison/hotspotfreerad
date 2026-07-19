<x-layouts.admin title="Expense Categories" heading="Expense Categories" subheading="Group expenses into useful operating cost buckets.">
    <livewire:admin.expense-categories-index :filters="$filters ?? []" />
</x-layouts.admin>
