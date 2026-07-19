<form wire:submit="save" class="relative max-w-4xl rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
    <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
        <div class="rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow-sm">
            Saving profile...
        </div>
    </div>

    @if ($savedMessage)
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <div class="grid gap-6">
        <section>
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                <div>
                    <h2 class="text-base font-semibold">Account</h2>
                    <p class="mt-1 text-sm text-zinc-500">Update the name shown across the admin area.</p>
                </div>

                <flux:badge :color="$user->isSuperAdmin() ? 'blue' : 'green'">
                    {{ $user->isSuperAdmin() ? 'Platform Admin' : 'Tenant Admin' }}
                </flux:badge>
            </div>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model.blur="name" icon="user" required />
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
            <div>
                <h2 class="text-base font-semibold">Password</h2>
                <p class="mt-1 text-sm text-zinc-500">Leave these fields blank if you are only updating your name.</p>
            </div>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:field class="md:col-span-2">
                    <flux:label>Current password</flux:label>
                    <flux:input type="password" wire:model.blur="current_password" icon="key" viewable />
                    <flux:error name="current_password" />
                </flux:field>

                <flux:field>
                    <flux:label>New password</flux:label>
                    <flux:input type="password" wire:model.blur="password" icon="key" viewable />
                    <flux:description>Use at least 8 characters.</flux:description>
                    <flux:error name="password" />
                </flux:field>

                <flux:field>
                    <flux:label>Confirm new password</flux:label>
                    <flux:input type="password" wire:model.blur="password_confirmation" icon="key" viewable />
                    <flux:error name="password_confirmation" />
                </flux:field>
            </div>
        </section>
    </div>

    <div class="mt-6 flex flex-wrap gap-3">
        <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save profile</span>
            <span wire:loading wire:target="save">Saving...</span>
        </flux:button>
        <flux:button href="{{ route('admin.dashboard') }}" variant="outline" icon="arrow-left">Back to dashboard</flux:button>
    </div>
</form>
