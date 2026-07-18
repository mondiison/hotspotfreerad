<x-layouts.admin
    :title="$tenant->exists ? 'Edit Tenant' : 'Add Tenant'"
    :heading="$tenant->exists ? 'Edit Tenant' : 'Add Tenant'"
    subheading="Tenant records group shops, routers, packages, and billing settings."
>
    <form method="POST" action="{{ $tenant->exists ? route('admin.tenants.update', $tenant) : route('admin.tenants.store') }}" class="max-w-2xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($tenant->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5">
            <label class="block">
                <span class="text-sm font-medium">Company name</span>
                <input name="company_name" value="{{ old('company_name', $tenant->company_name) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('company_name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Owner email</span>
                <input type="email" name="owner_email" value="{{ old('owner_email', $tenant->owner_email) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('owner_email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Subscription plan</span>
                <input name="subscription_plan" value="{{ old('subscription_plan', $tenant->subscription_plan ?? 'basic') }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('subscription_plan') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Trial ends at</span>
                <input type="datetime-local" name="trial_ends_at" value="{{ old('trial_ends_at', optional($tenant->trial_ends_at)->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('trial_ends_at') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $tenant->is_active ?? true)) class="rounded border-zinc-300">
                Active
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Tenant</button>
            <a href="{{ route('admin.tenants.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
