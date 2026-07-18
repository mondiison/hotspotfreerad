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
            <label class="block">
                <span class="text-sm font-medium">Plan name</span>
                <input name="name" value="{{ old('name', $plan->name) }}" placeholder="Growth" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Slug</span>
                <input name="slug" value="{{ old('slug', $plan->slug) }}" placeholder="growth" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                <span class="mt-1 block text-xs text-zinc-500">Leave blank to generate it from the plan name.</span>
                @error('slug') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Monthly price</span>
                <input type="number" step="0.01" min="0" name="monthly_price" value="{{ old('monthly_price', $plan->monthly_price ?? 0) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('monthly_price') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Currency</span>
                <input name="currency" value="{{ old('currency', $plan->currency ?? 'NGN') }}" maxlength="3" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 uppercase" required>
                @error('currency') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <section class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                <h2 class="text-sm font-semibold text-zinc-950">Usage limits</h2>
                <p class="mt-1 text-sm text-zinc-500">Leave a limit empty when the plan should be unlimited for that item.</p>

                <div class="mt-4 grid gap-5 md:grid-cols-3">
                    <label class="block">
                        <span class="text-sm font-medium">Shop limit</span>
                        <input type="number" min="1" name="shop_limit" value="{{ old('shop_limit', $plan->shop_limit) }}" placeholder="Unlimited" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        @error('shop_limit') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Router limit</span>
                        <input type="number" min="1" name="router_limit" value="{{ old('router_limit', $plan->router_limit) }}" placeholder="Unlimited" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        @error('router_limit') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Package limit</span>
                        <input type="number" min="1" name="package_limit" value="{{ old('package_limit', $plan->package_limit) }}" placeholder="Unlimited" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        @error('package_limit') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Features</span>
                <textarea name="features" rows="6" placeholder="One feature per line" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">{{ old('features', collect($plan->features ?? [])->implode("\n")) }}</textarea>
                <span class="mt-1 block text-xs text-zinc-500">These appear on billing screens and later can appear on tenant subscription checkout.</span>
                @error('features') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-2 text-sm md:col-span-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active ?? true)) class="rounded border-zinc-300">
                Active plan
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Plan</button>
            <a href="{{ route('admin.billing.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
