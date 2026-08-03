<x-layouts.admin
    title="POS Devices"
    heading="POS Devices"
    subheading="Register POS terminals for password Wi-Fi, app-managed renewal, and RADIUS MAC authentication."
>
    <livewire:admin.pos-devices-index :filters="$filters ?? []" />
</x-layouts.admin>
