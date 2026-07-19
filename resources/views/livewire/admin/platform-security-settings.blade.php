<div class="max-w-5xl space-y-6">
    @if ($savedMessage)
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <section class="grid gap-4 md:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">Super admins</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($superAdminCount) }}</p>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">2FA enabled</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-700">{{ number_format($superAdminsWithTwoFactor) }}</p>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">Need setup</p>
            <p class="mt-2 text-3xl font-semibold {{ $superAdminsMissingTwoFactor > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ number_format($superAdminsMissingTwoFactor) }}</p>
        </div>
    </section>

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

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <div class="flex flex-col justify-between gap-3 border-b border-zinc-200 bg-zinc-50 px-5 py-4 md:flex-row md:items-center">
            <div>
                <h2 class="text-base font-semibold">Super Admin Compliance</h2>
                <p class="mt-1 text-sm text-zinc-500">Use this before enabling enforcement so every platform admin is ready.</p>
            </div>

            <flux:badge :color="$superAdminsMissingTwoFactor > 0 ? 'amber' : 'emerald'">
                {{ $superAdminsMissingTwoFactor > 0 ? $superAdminsMissingTwoFactor.' need setup' : 'All ready' }}
            </flux:badge>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500">
                    <tr>
                        <th class="px-5 py-3 font-medium">Admin</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Last updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($superAdmins as $admin)
                        <tr>
                            <td class="px-5 py-3">
                                <p class="font-medium">{{ $admin->name }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $admin->email }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <flux:badge :color="$admin->hasTwoFactorEnabled() ? 'emerald' : 'amber'">
                                    {{ $admin->hasTwoFactorEnabled() ? '2FA enabled' : '2FA not enabled' }}
                                </flux:badge>
                            </td>
                            <td class="px-5 py-3 text-zinc-500">
                                {{ $admin->two_factor_confirmed_at?->diffForHumans() ?? 'Not enabled' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-5 py-8 text-center text-zinc-500">
                                No super admin accounts found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
