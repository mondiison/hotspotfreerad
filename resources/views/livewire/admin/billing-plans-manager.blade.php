<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="space-y-2">
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif

            @error('billing_plan')
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            Add Plan
        </flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($plans as $plan)
            <section wire:key="billing-plan-{{ $plan->id }}" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">{{ $plan->name }}</h2>
                        <p class="mt-1 text-sm text-zinc-500">{{ $plan->slug }}</p>
                    </div>
                    <flux:badge :color="$plan->is_active ? 'emerald' : 'zinc'" size="sm">{{ $plan->is_active ? 'Active' : 'Hidden' }}</flux:badge>
                </div>
                <p class="mt-4 text-2xl font-semibold">{{ $plan->currency }} {{ number_format($plan->monthly_price, 2) }}</p>
                <dl class="mt-4 grid grid-cols-3 gap-2 text-xs text-zinc-500">
                    <div>
                        <dt>Shops</dt>
                        <dd class="mt-1 font-medium text-zinc-950">{{ $plan->shop_limit ?? 'Unlimited' }}</dd>
                    </div>
                    <div>
                        <dt>Routers</dt>
                        <dd class="mt-1 font-medium text-zinc-950">{{ $plan->router_limit ?? 'Unlimited' }}</dd>
                    </div>
                    <div>
                        <dt>Plans</dt>
                        <dd class="mt-1 font-medium text-zinc-950">{{ $plan->package_limit ?? 'Unlimited' }}</dd>
                    </div>
                </dl>
                @if ($plan->features)
                    <ul class="mt-4 space-y-1 text-sm text-zinc-600">
                        @foreach ($plan->features as $feature)
                            <li>{{ $feature }}</li>
                        @endforeach
                    </ul>
                @endif
                <div class="mt-5 flex gap-2">
                    <flux:button type="button" size="sm" variant="outline" icon="pencil-square" wire:click="edit({{ $plan->id }})" wire:loading.attr="disabled" wire:target="edit({{ $plan->id }})">Edit</flux:button>
                    <flux:button type="button" size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $plan->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $plan->id }})">Delete</flux:button>
                </div>
            </section>
        @endforeach
    </div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingPlanId ? 'Edit Billing Plan' : 'Add Billing Plan' }}</flux:heading>
                <flux:text class="mt-2">Plans control platform subscription pricing and tenant usage limits.</flux:text>
            </div>

            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Plan name</flux:label>
                        <flux:input wire:model.blur="name" icon="tag" placeholder="Growth" required />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Slug</flux:label>
                        <flux:input wire:model.blur="slug" icon="link" placeholder="growth" />
                        <flux:description>Leave blank to generate it from the plan name.</flux:description>
                        <flux:error name="slug" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Monthly price</flux:label>
                        <flux:input type="number" step="0.01" min="0" wire:model.blur="monthly_price" icon="banknotes" required />
                        <flux:error name="monthly_price" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Currency</flux:label>
                        <flux:input maxlength="3" wire:model.blur="currency" class="uppercase" icon="currency-dollar" required />
                        <flux:error name="currency" />
                    </flux:field>

                    <section class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 md:col-span-2">
                        <h2 class="text-sm font-semibold text-zinc-950">Usage limits</h2>
                        <p class="mt-1 text-sm leading-6 text-zinc-600">Leave a limit empty for unlimited. Example: Starter might allow 1 shop and 1 router, while Growth can allow 5 shops and 10 routers.</p>

                        <div class="mt-4 grid gap-5 md:grid-cols-3">
                            <flux:field>
                                <flux:label>Shop limit</flux:label>
                                <flux:input type="number" min="1" wire:model.blur="shop_limit" placeholder="Unlimited" />
                                <flux:error name="shop_limit" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Router limit</flux:label>
                                <flux:input type="number" min="1" wire:model.blur="router_limit" placeholder="Unlimited" />
                                <flux:error name="router_limit" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Package limit</flux:label>
                                <flux:input type="number" min="1" wire:model.blur="package_limit" placeholder="Unlimited" />
                                <flux:error name="package_limit" />
                            </flux:field>
                        </div>
                    </section>

                    <flux:field class="md:col-span-2">
                        <flux:label>Features</flux:label>
                        <flux:textarea wire:model.blur="features" rows="6" placeholder="One feature per line" />
                        <flux:description>These appear on billing screens and can later be reused on tenant checkout.</flux:description>
                        <flux:error name="features" />
                    </flux:field>

                    <div class="md:col-span-2">
                        <flux:checkbox wire:model.live="is_active" label="Active plan" />
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <h2 class="text-sm font-semibold text-zinc-950">Plan guide</h2>
                    <p class="mt-1 text-sm leading-6 text-zinc-600">
                        Active plans can be purchased by tenants. Hidden plans remain available for existing subscriptions but are not offered as new choices.
                    </p>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Plan</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Billing Plan</flux:heading>
                <flux:text class="mt-2">Delete only unused plans. Hide plans that tenants have already subscribed to.</flux:text>
            </div>

            @if ($deletingPlan)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingPlan->name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $deletingPlan->currency }} {{ number_format($deletingPlan->monthly_price, 2) }} / month</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Plan</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
