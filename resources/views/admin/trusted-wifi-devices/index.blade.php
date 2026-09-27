<x-layouts.admin
    title="Trusted Wi-Fi Devices"
    heading="Trusted Wi-Fi Devices"
    subheading="Register staff and management devices by MAC address so the password alone isn't enough to join those networks."
>
    @include('admin.partials.nav-group-tabs', ['group' => 'access'])
    <livewire:admin.trusted-wifi-devices-index :filters="$filters ?? []" />
</x-layouts.admin>
