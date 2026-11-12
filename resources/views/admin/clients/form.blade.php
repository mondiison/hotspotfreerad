<x-layouts.admin
    :title="$client->exists ? 'Edit client' : 'New client'"
    :heading="$client->exists ? 'Edit client' : 'New client'"
    subheading="Pick a plan and which shops this client owns. A new client starts on that plan's free trial automatically."
>
    <form
        method="POST"
        action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}"
        class="max-w-xl space-y-6"
    >
        @csrf
        @if ($client->exists)
            @method('PUT')
        @endif

        <flux:input name="name" label="Name" :value="old('name', $client->name)" required />

        @unless ($client->exists)
            <flux:select name="client_billing_plan_id" label="Plan">
                @foreach ($plans as $plan)
                    <flux:select.option value="{{ $plan->id }}">{{ $plan->name }} ({{ $plan->currency }} {{ number_format($plan->price, 2) }})</flux:select.option>
                @endforeach
            </flux:select>
            @if ($plans->isEmpty())
                <flux:text class="text-amber-600">You have no active client plans yet -- <a href="{{ route('admin.client-billing-plans.create') }}" wire:navigate class="underline">create one first</a>.</flux:text>
            @endif
        @endunless

        <div>
            <flux:label>Shops owned by this client</flux:label>
            <div class="mt-2 space-y-2">
                @forelse ($shops as $shop)
                    <flux:checkbox
                        name="shop_ids[]"
                        value="{{ $shop->id }}"
                        :checked="in_array($shop->id, old('shop_ids', $selectedShopIds ?? []))"
                        label="{{ $shop->name }}"
                    />
                @empty
                    <flux:text class="text-zinc-500">No unassigned shops available.</flux:text>
                @endforelse
            </div>
        </div>

        @if ($client->exists)
            <flux:checkbox name="is_active" value="1" :checked="old('is_active', $client->is_active)" label="Active" />
        @endif

        <flux:button type="submit" variant="primary">Save</flux:button>
    </form>
</x-layouts.admin>
