<x-layouts.admin
    :title="$plan->exists ? 'Edit Billing Plan' : 'Add Billing Plan'"
    :heading="$plan->exists ? 'Edit Billing Plan' : 'Add Billing Plan'"
    subheading="Platform plans control how tenants subscribe to the SaaS platform."
>
    <form method="POST" action="{{ $plan->exists ? route('admin.billing.plans.update', $plan) : route('admin.billing.plans.store') }}" class="max-w-4xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($plan->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            <flux:field>
                <flux:label>Plan name</flux:label>
                <flux:input name="name" value="{{ old('name', $plan->name) }}" icon="tag" placeholder="Growth" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>Slug</flux:label>
                <flux:input name="slug" value="{{ old('slug', $plan->slug) }}" icon="link" placeholder="growth" />
                <flux:description>Leave blank to generate it from the plan name.</flux:description>
                <flux:error name="slug" />
            </flux:field>

            <flux:field>
                <flux:label>Monthly price</flux:label>
                <flux:input type="number" step="0.01" min="0" name="monthly_price" value="{{ old('monthly_price', $plan->monthly_price ?? 0) }}" icon="banknotes" required />
                <flux:error name="monthly_price" />
            </flux:field>

            <flux:field>
                <flux:label>Currency</flux:label>
                <flux:input name="currency" value="{{ old('currency', $plan->currency ?? 'NGN') }}" maxlength="3" class="uppercase" icon="currency-dollar" required />
                <flux:error name="currency" />
            </flux:field>

            <section class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                <h2 class="text-sm font-semibold text-zinc-950">Usage limits</h2>
                <p class="mt-1 text-sm text-zinc-500">Leave a limit empty when the plan should be unlimited for that item.</p>

                <div class="mt-4 grid gap-5 md:grid-cols-3">
                    <flux:field>
                        <flux:label>Shop limit</flux:label>
                        <flux:input type="number" min="1" name="shop_limit" value="{{ old('shop_limit', $plan->shop_limit) }}" placeholder="Unlimited" />
                        <flux:error name="shop_limit" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Router limit</flux:label>
                        <flux:input type="number" min="1" name="router_limit" value="{{ old('router_limit', $plan->router_limit) }}" placeholder="Unlimited" />
                        <flux:error name="router_limit" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Package limit</flux:label>
                        <flux:input type="number" min="1" name="package_limit" value="{{ old('package_limit', $plan->package_limit) }}" placeholder="Unlimited" />
                        <flux:error name="package_limit" />
                    </flux:field>
                </div>
            </section>

            <flux:field class="md:col-span-2">
                <flux:label>Features</flux:label>
                <flux:textarea name="features" rows="6" placeholder="One feature per line">{{ old('features', collect($plan->features ?? [])->implode("\n")) }}</flux:textarea>
                <flux:description>These appear on billing screens and later can appear on tenant subscription checkout.</flux:description>
                <flux:error name="features" />
            </flux:field>

            <div class="md:col-span-2">
                <flux:checkbox name="is_active" value="1" :checked="(bool) old('is_active', $plan->is_active ?? true)" label="Active plan" />
            </div>
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save Plan</flux:button>
            <flux:button href="{{ route('admin.billing.index') }}" variant="outline">Cancel</flux:button>
        </div>
    </form>
</x-layouts.admin>
