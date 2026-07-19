<x-layouts.admin title="Users" heading="Users" subheading="Admin accounts that can sign in to manage the platform or tenant workspace.">
    <livewire:admin.users-index :filters="$filters ?? []" />
</x-layouts.admin>
