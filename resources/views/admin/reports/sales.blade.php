<x-layouts.admin title="Sales Report" heading="Sales Report" subheading="Successful hotspot sales by date range and period.">
    @include('admin.partials.nav-group-tabs', ['group' => 'transactions'])
    <livewire:admin.sales-report :filters="$filters ?? []" />
</x-layouts.admin>
