<x-layouts.admin
    :title="$package->exists ? 'Edit Package' : 'Add Package'"
    :heading="$package->exists ? 'Edit Package' : 'Add Package'"
    subheading="Build sellable hotspot data plans. Each save syncs a reusable RADIUS profile."
>
    <livewire:admin.package-form :package="$package" :shops="$shops" :billing-usage="$billingUsage" />
</x-layouts.admin>
