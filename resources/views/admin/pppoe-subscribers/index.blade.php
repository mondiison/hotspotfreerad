<x-layouts.admin title="PPPoE Customers" heading="PPPoE Customers" subheading="Username/password subscriber accounts provisioned through FreeRADIUS.">
    @include('admin.partials.nav-group-tabs', ['group' => 'access'])
    <livewire:admin.pppoe-subscribers-index :filters="$filters ?? []" />
</x-layouts.admin>
