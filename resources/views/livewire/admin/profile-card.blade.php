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
                <div
                    x-data="{ copied: false }"
                    class="mt-5 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div>
                            <p class="font-medium">Save these recovery codes now. They will not be shown again.</p>
                            <p class="mt-1 text-amber-800">Copy them into a password manager or keep them somewhere private.</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <flux:button
                                type="button"
                                variant="outline"
                                size="sm"
                                icon="clipboard"
                                @click="copied = await window.copyText(@js(implode(PHP_EOL, $plainRecoveryCodes))); setTimeout(() => copied = false, 1800)"
                            >
                                <span x-show="! copied">Copy recovery codes</span>
                                <span x-cloak x-show="copied">Copied</span>
                            </flux:button>

                            <flux:button
                                type="button"
                                variant="outline"
                                size="sm"
                                icon="arrow-down-tray"
                                @click="window.downloadTextFile(@js('hotspotfreerad-recovery-codes-'.str($user->email)->replace(['@', '.'], '-')->slug().'.txt'), @js('HotspotFreeRAD recovery codes for '.$user->email.PHP_EOL.PHP_EOL.implode(PHP_EOL, $plainRecoveryCodes).PHP_EOL))"
                            >
                                Download
                            </flux:button>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-2 font-mono text-xs sm:grid-cols-2">
                        @foreach ($plainRecoveryCodes as $code)
                            <span class="rounded border border-amber-200 bg-white px-3 py-2">{{ $code }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($twoFactorSetupSecret && ! $user->hasTwoFactorEnabled())
                <div class="mt-5 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_260px]">
                        <div>
                            <p class="text-sm font-medium">Add this account to your authenticator app</p>
                            <p class="mt-2 text-sm leading-6 text-zinc-600">Scan the QR code with Google Authenticator, Authy, Microsoft Authenticator, or 1Password. Use the setup key if the app asks for manual entry.</p>

                            <div class="mt-4 rounded-md border border-zinc-200 bg-white p-3">
                                <p class="text-xs font-medium uppercase text-zinc-500">Scan QR code</p>
                                <div
                                    wire:ignore
                                    x-data
                                    x-init="window.renderQrCode($refs.qr, @js($twoFactorProvisioningUri))"
                                    class="mt-3 flex justify-center"
                                >
                                    <div x-ref="qr" class="grid min-h-48 w-48 place-items-center rounded-md border border-zinc-100 bg-white p-2 text-center text-sm text-zinc-500">
                                        Preparing QR code...
                                    </div>
                                </div>
                            </div>

                            <div x-data="{ copied: false }" class="mt-4 rounded-md border border-zinc-200 bg-white p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-medium uppercase text-zinc-500">Setup key</p>
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-zinc-600 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-950"
                                        @click="copied = await window.copyText(@js($twoFactorSetupSecret)); setTimeout(() => copied = false, 1800)"
                                    >
                                        <span x-show="! copied">Copy setup key</span>
                                        <span x-cloak x-show="copied">Copied</span>
                                    </button>
                                </div>
                                <p class="mt-2 break-all font-mono text-sm text-zinc-900">{{ $twoFactorSetupSecret }}</p>
                            </div>

                            <div x-data="{ copied: false }" class="mt-3 rounded-md border border-zinc-200 bg-white p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-medium uppercase text-zinc-500">Authenticator URI</p>
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-zinc-600 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-950"
                                        @click="copied = await window.copyText(@js($twoFactorProvisioningUri)); setTimeout(() => copied = false, 1800)"
                                    >
                                        <span x-show="! copied">Copy URI</span>
                                        <span x-cloak x-show="copied">Copied</span>
                                    </button>
                                </div>
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

        <section class="border-t border-zinc-200 pt-6">
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                <div>
                    <h2 class="text-base font-semibold">Active Sessions</h2>
                    <p class="mt-1 text-sm text-zinc-500">Review browsers currently signed in to this admin account.</p>
                </div>

                <flux:badge color="zinc">
                    {{ $sessionDriverSupported ? $activeSessions->count().' tracked' : 'Unavailable' }}
                </flux:badge>
            </div>

            @if (! $sessionDriverSupported)
                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Active session management needs the database session driver. Set SESSION_DRIVER=database to enable it.
                </div>
            @else
                <div class="mt-5 overflow-hidden rounded-lg border border-zinc-200">
                    <div class="divide-y divide-zinc-200 bg-white">
                        @forelse ($activeSessions as $session)
                            <div class="flex flex-col justify-between gap-3 p-4 md:flex-row md:items-center">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-medium">{{ $session['device'] }}</p>
                                        @if ($session['is_current'])
                                            <flux:badge color="emerald" size="sm">Current session</flux:badge>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-zinc-500">{{ $session['ip_address'] }}</p>
                                    <p class="mt-1 truncate text-xs text-zinc-400">{{ $session['user_agent'] ?: 'No user agent recorded' }}</p>
                                </div>

                                <div class="shrink-0 text-sm text-zinc-500">
                                    {{ $session['last_active']->diffForHumans() }}
                                </div>
                            </div>
                        @empty
                            <div class="p-4 text-sm text-zinc-500">
                                No active sessions were found yet.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="mt-5 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="text-sm font-medium">Sign out other sessions</p>
                    <p class="mt-2 text-sm leading-6 text-zinc-600">This keeps your current browser signed in and removes every other active session for this account.</p>

                    <div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
                        <flux:field>
                            <flux:label>Current password</flux:label>
                            <flux:input type="password" wire:model.blur="logout_other_sessions_password" icon="key" viewable />
                            <flux:error name="logout_other_sessions_password" />
                        </flux:field>

                        <flux:button type="button" wire:click="logoutOtherSessions" wire:loading.attr="disabled" wire:target="logoutOtherSessions" variant="outline" icon="arrow-left-start-on-rectangle" class="md:mt-6">
                            Sign out others
                        </flux:button>
                    </div>
                </div>
            @endif
        </section>

        <section class="border-t border-zinc-200 pt-6">
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                <div>
                    <h2 class="text-base font-semibold">Security Activity</h2>
                    <p class="mt-1 text-sm text-zinc-500">Recent sign-in and profile security events for this account.</p>
                </div>

                <flux:badge color="zinc">
                    {{ $securityActivities->count() }} recent
                </flux:badge>
            </div>

            <div class="mt-5 overflow-hidden rounded-lg border border-zinc-200 bg-white">
                <div class="divide-y divide-zinc-200">
                    @forelse ($securityActivities as $activity)
                        <div class="flex flex-col justify-between gap-3 p-4 md:flex-row md:items-start">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-600">
                                        @if (str_contains($activity->action, 'two_factor'))
                                            <flux:icon.shield-check class="size-4" />
                                        @elseif (str_contains($activity->action, 'login') || str_contains($activity->action, 'logout'))
                                            <flux:icon.arrow-left-start-on-rectangle class="size-4" />
                                        @else
                                            <flux:icon.key class="size-4" />
                                        @endif
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-medium">{{ $activity->label }}</p>
                                        <p class="mt-1 text-sm text-zinc-500">{{ $activity->ip_address ?: 'Unknown IP' }}</p>
                                    </div>
                                </div>

                                <p class="mt-2 truncate text-xs text-zinc-400">{{ $activity->user_agent ?: 'No user agent recorded' }}</p>
                            </div>

                            <div class="shrink-0 text-sm text-zinc-500">
                                {{ $activity->created_at->diffForHumans() }}
                            </div>
                        </div>
                    @empty
                        <div class="p-4 text-sm text-zinc-500">
                            No security activity has been recorded yet.
                        </div>
                    @endforelse
                </div>
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
