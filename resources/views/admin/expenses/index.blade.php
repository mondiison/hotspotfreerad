<x-layouts.admin title="Expenses" heading="Expenses" subheading="Track operating costs so sales reports can show practical profit.">
    <livewire:admin.expenses-index :filters="request()->query()" />
</x-layouts.admin>
