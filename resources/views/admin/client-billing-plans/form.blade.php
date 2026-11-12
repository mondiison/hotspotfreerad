<x-layouts.admin
    :title="$plan->exists ? 'Edit client plan' : 'New client plan'"
    :heading="$plan->exists ? 'Edit client plan' : 'New client plan'"
    subheading="Shown to a client when they pick a plan, and charged to them once their free trial lapses."
>
    <form
        method="POST"
        action="{{ $plan->exists ? route('admin.client-billing-plans.update', $plan) : route('admin.client-billing-plans.store') }}"
        class="max-w-xl space-y-6"
    >
        @csrf
        @if ($plan->exists)
            @method('PUT')
        @endif

        <flux:input name="name" label="Name" :value="old('name', $plan->name)" required />

        <div class="grid grid-cols-2 gap-4">
            <flux:input type="number" step="0.01" name="price" label="Price" :value="old('price', $plan->price)" required />
            <flux:input name="currency" label="Currency" :value="old('currency', $plan->currency ?: 'NGN')" maxlength="3" required />
        </div>

        <flux:input type="number" name="shop_limit" label="Shop limit (blank = unlimited)" :value="old('shop_limit', $plan->shop_limit)" />

        <flux:textarea name="features" label="Features (one per line)" rows="4">{{ old('features', is_array($plan->features) ? implode("\n", $plan->features) : '') }}</flux:textarea>

        <flux:checkbox name="is_active" value="1" :checked="old('is_active', $plan->exists ? $plan->is_active : true)" label="Active (selectable for new clients)" />

        <flux:button type="submit" variant="primary">Save</flux:button>
    </form>
</x-layouts.admin>
