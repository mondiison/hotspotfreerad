<x-layouts.admin title="Payments" heading="Payments" subheading="Customer hotspot payment attempts, confirmations, and provisioning status.">
    <livewire:admin.payments-index :filters="$filters ?? []" />
</x-layouts.admin>
