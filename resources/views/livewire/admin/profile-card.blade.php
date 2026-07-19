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

        <section class="border-t border-zinc-200 pt-6">
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                <div>
                    <h2 class="text-base font-semibold">Two-Factor Authentication</h2>
                    <p class="mt-1 text-sm text-zinc-500">Require a one-time code from an authenticator app when signing in.</p>
                </div>

                <flux:badge :color="$user->hasTwoFactorEnabled() ? 'emerald' : 'zinc'">
                    {{ $user->hasTwoFactorEnabled() ? 'Enabled' : 'Not enabled' }}
                </flux:badge>
            </div>

            @if ($plainRecoveryCodes)
                <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-medium">Save these recovery codes now. They will not be shown again.</p>
                    <div class="mt-3 grid gap-2 font-mono text-xs sm:grid-cols-2">
                        @foreach ($plainRecoveryCodes as $code)
                            <span class="rounded border border-amber-200 bg-white px-3 py-2">{{ $code }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($twoFactorSetupSecret && ! $user->hasTwoFactorEnabled())
                <div class="mt-5 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_240px]">
                        <div>
                            <p class="text-sm font-medium">Add this account to your authenticator app</p>
                            <p class="mt-2 text-sm leading-6 text-zinc-600">Choose manual setup in Google Authenticator, Authy, Microsoft Authenticator, or 1Password, then enter this setup key.</p>

                            <div class="mt-4 rounded-md border border-zinc-200 bg-white p-3">
                                <p class="text-xs font-medium uppercase text-zinc-500">Setup key</p>
                                <p class="mt-2 break-all font-mono text-sm text-zinc-900">{{ $twoFactorSetupSecret }}</p>
                            </div>

                            <div class="mt-3 rounded-md border border-zinc-200 bg-white p-3">
                                <p class="text-xs font-medium uppercase text-zinc-500">Authenticator URI</p>
                                <p class="mt-2 break-all font-mono text-xs text-zinc-600">{{ $twoFactorProvisioningUri }}</p>
                            </div>
                        </div>

                        <div class="rounded-md border border-zinc-200 bg-white p-4 text-sm text-zinc-600">
                            <p class="font-medium text-zinc-900">Then verify</p>
                            <p class="mt-2">Enter the 6-digit code from the app to finish enabling 2FA.</p>

                            <flux:field class="mt-4">
                                <flux:label>Authentication code</flux:label>
                                <flux:input wire:model.blur="two_factor_code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />
                                <flux:error name="two_factor_code" />
                            </flux:field>

                            <flux:button type="button" wire:click="confirmTwoFactor" wire:loading.attr="disabled" wire:target="confirmTwoFactor" variant="primary" icon="shield-check" class="mt-4 w-full">
                                Confirm 2FA
                            </flux:button>
                        </div>
                    </div>
                </div>
            @elseif ($user->hasTwoFactorEnabled())
                <div class="mt-5 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                        <p class="text-sm font-medium">Recovery codes</p>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">Use one recovery code if you lose access to your authenticator app. Regenerating codes invalidates the old set.</p>
                        <flux:button type="button" wire:click="regenerateRecoveryCodes" wire:loading.attr="disabled" wire:target="regenerateRecoveryCodes" variant="outline" icon="arrow-path" class="mt-4">
                            Regenerate codes
                        </flux:button>
                    </div>

                    <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                        <p class="text-sm font-medium text-red-950">Disable two-factor authentication</p>
                        <p class="mt-2 text-sm leading-6 text-red-800">Enter your current password to remove the extra login check from this account.</p>
                        <flux:field class="mt-4">
                            <flux:label>Current password</flux:label>
                            <flux:input type="password" wire:model.blur="two_factor_disable_password" icon="key" viewable />
                            <flux:error name="two_factor_disable_password" />
                        </flux:field>
                        <flux:button type="button" wire:click="disableTwoFactor" wire:loading.attr="disabled" wire:target="disableTwoFactor" variant="danger" icon="shield-exclamation" class="mt-4">
                            Disable 2FA
                        </flux:button>
                    </div>
                </div>
            @else
                <div class="mt-5 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="text-sm leading-6 text-zinc-600">Recommended for every super admin and tenant admin. Setup takes about one minute and protects the account even if a password is exposed.</p>
                    <flux:button type="button" wire:click="startTwoFactorSetup" wire:loading.attr="disabled" wire:target="startTwoFactorSetup" variant="primary" icon="shield-check" class="mt-4">
                        Enable 2FA
                    </flux:button>
                </div>
            @endif
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
