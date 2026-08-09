<div>
    <flux:dropdown position="bottom" align="end">
        <flux:button type="button" variant="ghost" icon="bell" aria-label="Notifications" class="relative">
            @if ($unreadCount > 0)
                <span class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white">
                    {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                </span>
            @endif
        </flux:button>

        <flux:popover class="w-80 p-0">
            <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-700 px-4 py-3">
                <p class="text-sm font-semibold">Notifications</p>
                @if ($unreadCount > 0)
                    <button type="button" wire:click="markAllAsRead" class="text-xs font-medium text-blue-600 hover:underline">Mark all read</button>
                @endif
            </div>

            <div class="max-h-96 divide-y divide-zinc-100 dark:divide-zinc-800 overflow-y-auto">
                @forelse ($notifications as $notification)
                    <a
                        href="{{ $notification->data['url'] ?? '#' }}"
                        wire:navigate
                        wire:click="markAsRead('{{ $notification->id }}')"
                        class="block px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800 {{ $notification->read_at ? '' : 'bg-blue-50/50' }}"
                    >
                        <div class="flex items-start gap-2">
                            <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-blue-600' }}"></span>
                            <div class="min-w-0">
                                <p class="font-medium">{{ $notification->data['label'] ?? 'Notification' }}</p>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->data['message'] ?? '' }}</p>
                                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $notification->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No notifications yet.</p>
                @endforelse
            </div>
        </flux:popover>
    </flux:dropdown>
</div>
