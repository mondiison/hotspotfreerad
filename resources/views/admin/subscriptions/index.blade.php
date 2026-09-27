<x-layouts.admin title="Access" heading="Access" subheading="Current and expired customer internet access provisioned through hotspot packages.">
    @include('admin.partials.nav-group-tabs', ['group' => 'access'])
    <livewire:admin.subscriptions-index :filters="$filters ?? []" />
</x-layouts.admin>
