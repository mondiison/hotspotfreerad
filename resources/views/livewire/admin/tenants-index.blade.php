<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="space-y-2">
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif

            @error('owner_email')
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            Add Tenant
        </flux:button>
    </div>

    <section class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_180px_220px_220px_auto] [&>*]:min-w-0">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search company, slug, or owner email" />
            <flux:select wire:model.live="status">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="billing_model">
                <flux:select.option value="">All billing models</flux:select.option>
                <flux:select.option value="subscription">Subscription</flux:select.option>
                <flux:select.option value="commission">Commission on sales</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="two_factor_status">
                <flux:select.option value="">All 2FA states</flux:select.option>
                <flux:select.option value="required">2FA required</flux:select.option>
                <flux:select.option value="ready">2FA ready</flux:select.option>
                <flux:select.option value="missing">2FA missing</flux:select.option>
            </flux:select>
            <flux:button type="button" variant="outline" icon="x-mark" class="w-full sm:col-span-2 xl:col-span-1 xl:w-auto" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status,billing_model,two_factor_status">
                Reset
            </flux:button>
        </div>
    </section>

    <section class="mb-4 grid gap-4 md:grid-cols-3">
        <button type="button" wire:click="$set('two_factor_status', 'required')" class="rounded-lg border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-zinc-300 hover:bg-zinc-50">
            <span class="text-xs font-semibold uppercase text-zinc-500">2FA policy</span>
            <span class="mt-2 block text-2xl font-semibold text-zinc-950">{{ number_format($securitySummary['required']) }}</span>
            <span class="mt-1 block text-sm text-zinc-500">Tenants requiring owner 2FA</span>
        </button>

        <button type="button" wire:click="$set('two_factor_status', 'ready')" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-left shadow-sm transition hover:border-emerald-300">
            <span class="text-xs font-semibold uppercase text-emerald-700">Ready</span>
            <span class="mt-2 block text-2xl font-semibold text-emerald-950">{{ number_format($securitySummary['ready']) }}</span>
            <span class="mt-1 block text-sm text-emerald-700">Owners with confirmed 2FA</span>
        </button>

        <button type="button" wire:click="$set('two_factor_status', 'missing')" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-left shadow-sm transition hover:border-amber-300">
            <span class="text-xs font-semibold uppercase text-amber-700">Needs setup</span>
            <span class="mt-2 block text-2xl font-semibold text-amber-950">{{ number_format($securitySummary['missing']) }}</span>
            <span class="mt-1 block text-sm text-amber-700">Required tenants still exposed</span>
        </button>
    </section>

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-[940px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Company</th>
                    <th class="px-4 py-3 font-medium">Public site</th>
                    <th class="px-4 py-3 font-medium">Owner</th>
                    <th class="px-4 py-3 font-medium">Owner access</th>
                    <th class="px-4 py-3 font-medium">Plan</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($tenants as $tenant)
                    @php($ownerUser = $ownerUsers->get($tenant->id))
                    <tr wire:key="tenant-{{ $tenant->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $tenant->company_name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">/{{ $tenant->slug }}</p>
                        </td>
                        <td class="px-4 py-3">
                            @if ($tenant->public_site_enabled)
                                <flux:button href="{{ $tenant->publicUrl() }}" target="_blank" variant="ghost" size="sm" icon="arrow-top-right-on-square">Open</flux:button>
                            @else
                                <flux:badge color="zinc">Disabled</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $tenant->owner_email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-2">
                                @if (! $ownerUser)
                                    <flux:badge color="amber">Login missing</flux:badge>
                                @elseif ($ownerUser->must_change_password)
                                    <flux:badge color="amber">Temporary password</flux:badge>
                                @elseif (! $ownerUser->is_active)
                                    <flux:badge color="red">Login inactive</flux:badge>
                                @else
                                    <flux:badge color="green">Ready</flux:badge>
                                @endif

                                @if ($tenant->require_two_factor)
                                    <flux:badge :color="$ownerUser?->hasTwoFactorEnabled() ? 'emerald' : 'amber'">
                                        {{ $ownerUser?->hasTwoFactorEnabled() ? '2FA ready' : '2FA required' }}
                                    </flux:badge>
                                @endif

                                <flux:button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    icon="envelope"
                                    data-reset-url="{{ route('admin.tenants.owner-reset-link', $tenant) }}"
                                    wire:click="sendResetLink({{ $tenant->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="sendResetLink({{ $tenant->id }})"
                                >
                                    Send reset link
                                </flux:button>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ ucfirst($tenant->subscription_plan) }}</p>
                            @if (($tenant->billing_model ?? 'subscription') === 'commission')
                                <p class="mt-1 text-xs text-zinc-500">{{ number_format((float) $tenant->commission_rate, 2) }}% commission</p>
                            @else
                                <p class="mt-1 text-xs text-zinc-500">Subscription billing</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$tenant->is_active ? 'green' : 'zinc'">{{ $tenant->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $tenant->id }})" wire:loading.attr="disabled" wire:target="edit({{ $tenant->id }})">Edit</flux:button>
                                <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $tenant->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $tenant->id }})">Delete</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center">
                            <p class="font-medium">No tenants match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Create a tenant to issue an admin login and public workspace slug.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add Tenant</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tenants->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-4xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingTenantId ? 'Edit Tenant' : 'Add Tenant' }}</flux:heading>
                <flux:text class="mt-2">Tenant records group shops, routers, packages, branding, owner access, and billing settings.</flux:text>
            </div>

            <form wire:submit.prevent="save" class="space-y-6">
                <section>
                    <h2 class="text-sm font-semibold uppercase text-zinc-500">Account</h2>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <flux:field>
                            <flux:label>Company name</flux:label>
                            <flux:input wire:model.blur="company_name" icon="building-office" required />
                            <flux:error name="company_name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Owner email</flux:label>
                            <flux:input type="email" wire:model.blur="owner_email" icon="envelope" required />
                            <flux:description>{{ $editingTenantId ? 'Use the row reset action to send a password link after saving.' : 'This email becomes the tenant admin login. A temporary password will be emailed after creation.' }}</flux:description>
                            <flux:error name="owner_email" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Subscription plan</flux:label>
                            <flux:input wire:model.blur="subscription_plan" icon="credit-card" required />
                            <flux:description>Example: free, basic, growth, pro, enterprise.</flux:description>
                            <flux:error name="subscription_plan" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Tenant billing model</flux:label>
                            <flux:select wire:model.live="form_billing_model">
                                <flux:select.option value="subscription">Subscription</flux:select.option>
                                <flux:select.option value="commission">Commission on sales</flux:select.option>
                            </flux:select>
                            <flux:description>Subscription bills tenants separately. Commission keeps a platform percentage from hotspot sales.</flux:description>
                            <flux:error name="billing_model" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Commission rate</flux:label>
                            <flux:input type="number" wire:model.blur="commission_rate" min="0" max="100" step="0.01" icon="receipt-percent" :disabled="$form_billing_model !== 'commission'" />
                            <flux:description>Example: 10 means the platform keeps 10% and tenant net is 90%.</flux:description>
                            <flux:error name="commission_rate" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Trial ends at</flux:label>
                            <flux:input type="datetime-local" wire:model.blur="trial_ends_at" />
                            <flux:error name="trial_ends_at" />
                        </flux:field>

                        <div class="md:col-span-2">
                            <flux:checkbox wire:model.live="is_active" label="Active tenant account" />
                        </div>

                        <div class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <flux:checkbox wire:model.live="require_two_factor" label="Require 2FA for tenant admins" />
                            <p class="mt-2 text-sm leading-6 text-zinc-600">
                                Tenant admins must enable two-factor authentication from Profile before opening the admin dashboard. Super admins are not affected by this tenant policy.
                            </p>

                            @if ($editingTenantId)
                                <div class="mt-4 rounded-lg border border-white bg-white p-3 shadow-sm">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-950">Owner 2FA readiness</p>
                                            @if (! $editingOwnerUser)
                                                <p class="mt-1 text-sm leading-6 text-zinc-600">No tenant admin login matches this owner email yet. Use the reset link action after saving to create or recover access.</p>
                                            @elseif ($editingOwnerUser->hasTwoFactorEnabled())
                                                <p class="mt-1 text-sm leading-6 text-zinc-600">The owner login has confirmed two-factor authentication and can satisfy this tenant policy.</p>
                                            @else
                                                <p class="mt-1 text-sm leading-6 text-zinc-600">The owner login exists, but two-factor authentication is not confirmed yet. If this policy is enabled, they will be sent to Profile before using the dashboard.</p>
                                            @endif
                                        </div>

                                        @if (! $editingOwnerUser)
                                            <flux:badge color="amber">Login missing</flux:badge>
                                        @elseif ($editingOwnerUser->hasTwoFactorEnabled())
                                            <flux:badge color="green">2FA ready</flux:badge>
                                        @else
                                            <flux:badge color="amber">Needs setup</flux:badge>
                                        @endif
                                    </div>
                                </div>
                            @else
                                <p class="mt-3 text-sm leading-6 text-zinc-600">
                                    New tenant owners receive their login first. If this is enabled now, their first dashboard visit will guide them through Profile and Security.
                                </p>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="border-t border-zinc-200 pt-6">
                    <div class="flex flex-col justify-between gap-2 md:flex-row md:items-start">
                        <div>
                            <h2 class="text-sm font-semibold uppercase text-zinc-500">Public site</h2>
                            <p class="mt-1 text-sm text-zinc-500">Each tenant can have a branded public page like {{ url('/demo-hotspot') }}.</p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <flux:field>
                            <flux:label>Unique slug</flux:label>
                            <flux:input wire:model.blur="slug" icon="link" placeholder="demo-hotspot" />
                            <flux:description>Use lowercase words with hyphens. Leave blank to generate it from company name.</flux:description>
                            <flux:error name="slug" />
                        </flux:field>

                        <div class="block">
                            <span class="text-sm font-medium">Brand color</span>
                            <flux:color-picker
                                wire:model.live="brand_color"
                                format="hex"
                                copyable
                                :swatches="['#0f766e', '#2563eb', '#7c3aed', '#dc2626', '#f59e0b', '#16a34a', '#0891b2', '#111827']"
                            />
                            <flux:description>Example: #0f766e. This accents buttons and public-site highlights.</flux:description>
                            <flux:error name="brand_color" />
                        </div>

                        <flux:field class="md:col-span-2">
                            <flux:label>Tagline</flux:label>
                            <flux:input wire:model.blur="public_site_tagline" icon="sparkles" placeholder="Fast Wi-Fi for guests, students, and daily users." />
                            <flux:error name="public_site_tagline" />
                        </flux:field>

                        <flux:field class="md:col-span-2">
                            <flux:label>About this hotspot business</flux:label>
                            <flux:textarea wire:model.blur="public_site_about" rows="4" placeholder="Tell customers what kind of locations you serve, support hours, or why your internet access is reliable." />
                            <flux:error name="public_site_about" />
                        </flux:field>

                        <div class="md:col-span-2">
                            <flux:checkbox wire:model.live="public_site_enabled" label="Public site enabled" />
                        </div>
                    </div>
                </section>

                <section class="border-t border-zinc-200 pt-6">
                    <h2 class="text-sm font-semibold uppercase text-zinc-500">Customer contact</h2>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <flux:field>
                            <flux:label>Contact phone</flux:label>
                            <flux:input wire:model.blur="contact_phone" icon="phone" placeholder="+234 800 000 0000" />
                            <flux:error name="contact_phone" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Contact email</flux:label>
                            <flux:input type="email" wire:model.blur="contact_email" icon="envelope" placeholder="support@example.com" />
                            <flux:error name="contact_email" />
                        </flux:field>

                        <flux:field class="md:col-span-2">
                            <flux:label>Contact address</flux:label>
                            <flux:textarea wire:model.blur="contact_address" rows="3" placeholder="Shop address or coverage area customers should recognize." />
                            <flux:error name="contact_address" />
                        </flux:field>
                    </div>
                </section>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <h2 class="text-sm font-semibold text-zinc-950">Owner access guide</h2>
                    <p class="mt-1 text-sm leading-6 text-zinc-600">
                        New tenants receive a temporary password and must change it on first login. Existing owners should use the reset link action so passwords are never exposed in admin screens.
                    </p>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Tenant</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Tenant</flux:heading>
                <flux:text class="mt-2">This removes the tenant record and related tenant data through database relationships. Use carefully.</flux:text>
            </div>

            @if ($deletingTenant)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingTenant->company_name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $deletingTenant->owner_email }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Tenant</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
