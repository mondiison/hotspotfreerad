<div class="mx-auto max-w-4xl space-y-6">
    <flux:card class="p-5">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(280px,360px)] lg:items-start">
            <div class="space-y-3">
                <flux:heading level="2" size="lg">Add a trusted device</flux:heading>
                <flux:text class="text-sm text-zinc-500">Register this browser or device as a passkey. Your device may ask for fingerprint, face unlock, PIN, or a hardware security key.</flux:text>
                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Passkeys work on localhost during development and on trusted HTTPS domains in production. Plain IP addresses such as your Pi LAN URL usually cannot register passkeys.
                </div>
            </div>

            <div x-data="passkeyManager()" class="rounded-md border border-zinc-200 bg-zinc-50 p-4">
                <label class="mb-1 block text-sm font-medium text-zinc-700">Passkey name</label>
                <input x-model="name" type="text" class="w-full rounded-md border-zinc-300 text-sm" placeholder="Office laptop" />
                <p x-cloak x-show="message" x-text="message" class="mt-2 text-xs" :class="messageType === 'error' ? 'text-red-600' : 'text-emerald-700'"></p>
                <flux:button type="button" variant="primary" class="mt-3 w-full" x-bind:disabled="loading" x-on:click="register">
                    <span x-show="! loading">Add passkey</span>
                    <span x-show="loading">Waiting for device...</span>
                </flux:button>
            </div>
        </div>
    </flux:card>

    <flux:card class="overflow-hidden">
        <div class="border-b border-zinc-200 px-5 py-4">
            <flux:heading level="2" size="lg">Registered passkeys</flux:heading>
        </div>
        <div class="divide-y divide-zinc-100">
            @forelse($passkeys as $passkey)
                <div class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center md:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="font-medium text-zinc-900">{{ $passkey->name }}</div>
                            @if($passkey->authenticator)
                                <flux:badge color="zinc">{{ $passkey->authenticator }}</flux:badge>
                            @endif
                        </div>
                        <div class="mt-1 text-xs text-zinc-500">
                            Added {{ $passkey->created_at?->format('M j, Y H:i') ?? '-' }} - Last used {{ $passkey->last_used_at?->format('M j, Y H:i') ?? 'never' }}
                        </div>
                    </div>
                    <flux:button size="sm" variant="danger" wire:click="deletePasskey({{ $passkey->id }})" wire:confirm="Remove this passkey from your account?">
                        Remove
                    </flux:button>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm text-zinc-500">
                    No passkeys have been added yet.
                </div>
            @endforelse
        </div>
    </flux:card>
</div>
