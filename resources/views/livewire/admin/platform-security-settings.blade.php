<div class="max-w-4xl space-y-6">
    @if ($savedMessage)
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <form wire:submit="save" class="relative rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
        <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
            <div class="rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow-sm">
                Saving security settings...
            </div>
        </div>

        <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
            <div>
                <h2 class="text-base font-semibold">Admin Two-Factor Policy</h2>
                <p class="mt-1 text-sm leading-6 text-zinc-500">Require super admins to enable authenticator-app 2FA before they can use platform administration screens.</p>
            </div>

            <flux:badge :color="$require_super_admin_two_factor ? 'emerald' : 'zinc'">
                {{ $require_super_admin_two_factor ? 'Required' : 'Optional' }}
            </flux:badge>
        </div>

        <div class="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
            <flux:checkbox wire:model.live="require_super_admin_two_factor" label="Require 2FA for super admins" />
            <p class="mt-2 text-sm leading-6 text-zinc-600">Super admins without 2FA will be redirected to Profile and Security. Login still works, but dashboard access is held until 2FA is enabled.</p>
        </div>

        <div class="mt-6 flex justify-end">
            <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save security policy</span>
                <span wire:loading wire:target="save">Saving...</span>
            </flux:button>
        </div>
    </form>
</div>
