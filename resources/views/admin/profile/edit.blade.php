<x-layouts.admin title="My Profile" heading="My Profile" subheading="Manage your admin identity and password.">
    <form method="POST" action="{{ route('admin.profile.update') }}" class="max-w-3xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @method('PUT')

        <div class="grid gap-6">
            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Account</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Name</flux:label>
                        <flux:input name="name" value="{{ old('name', $user->name) }}" icon="user" required />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Email</flux:label>
                        <flux:input type="email" value="{{ $user->email }}" icon="envelope" disabled />
                        <flux:description>Email changes are managed from Users or Tenant owner settings.</flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>Role</flux:label>
                        <flux:input value="{{ str_replace('_', ' ', $user->role) }}" icon="shield-check" disabled />
                    </flux:field>

                    <flux:field>
                        <flux:label>Tenant</flux:label>
                        <flux:input value="{{ $user->tenant?->company_name ?? 'Platform' }}" icon="building-storefront" disabled />
                    </flux:field>
                </div>
            </section>

            <section class="border-t border-zinc-200 pt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Password</h2>
                <p class="mt-1 text-sm text-zinc-500">Leave these fields blank if you are only updating your name.</p>

                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <flux:field class="md:col-span-2">
                        <flux:label>Current password</flux:label>
                        <flux:input type="password" name="current_password" icon="key" viewable />
                        <flux:error name="current_password" />
                    </flux:field>

                    <flux:field>
                        <flux:label>New password</flux:label>
                        <flux:input type="password" name="password" icon="key" viewable />
                        <flux:description>Use at least 8 characters.</flux:description>
                        <flux:error name="password" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Confirm new password</flux:label>
                        <flux:input type="password" name="password_confirmation" icon="key" viewable />
                    </flux:field>
                </div>
            </section>
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save Profile</flux:button>
            <flux:button href="{{ route('admin.dashboard') }}" variant="outline">Cancel</flux:button>
        </div>
    </form>
</x-layouts.admin>
