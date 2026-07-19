<div class="space-y-5">
    <div class="flex justify-end">
        <flux:button href="{{ route('admin.security-activity.export', $exportQuery) }}" variant="outline" icon="arrow-down-tray">Export CSV</flux:button>
    </div>

    <section class="grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">Total events</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($summary['total']) }}</p>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">Sign-ins</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($summary['sign_ins']) }}</p>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">Needs attention</p>
            <p class="mt-2 text-3xl font-semibold text-rose-700">{{ number_format($summary['attention']) }}</p>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">Passkey events</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-700">{{ number_format($summary['passkeys']) }}</p>
        </div>
    </section>

    <section class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 lg:grid-cols-[1fr_180px_160px_180px_180px_auto]">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search user, action, IP, tenant" />

            <flux:select wire:model.live="action_group">
                <flux:select.option value="">All events</flux:select.option>
                @foreach ($actionGroups as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="attention">
                <flux:select.option value="">All priorities</flux:select.option>
                <flux:select.option value="1">Attention only</flux:select.option>
            </flux:select>

            @if (auth()->user()->isSuperAdmin())
                <flux:select wire:model.live="tenant_id">
                    <flux:select.option value="">All tenants</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->company_name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <div></div>
            @endif

            <flux:select wire:model.live="date_preset">
                @foreach ($datePresets as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="button" variant="outline" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,action_group,attention,tenant_id,date_preset">
                Reset
            </flux:button>
        </div>
    </section>

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Event</th>
                        <th class="px-4 py-3 font-medium">Priority</th>
                        <th class="px-4 py-3 font-medium">Admin</th>
                        <th class="px-4 py-3 font-medium">Tenant</th>
                        <th class="px-4 py-3 font-medium">IP</th>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 text-right font-medium">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($activities as $activity)
                        <tr wire:key="security-activity-{{ $activity->id }}">
                            <td class="px-4 py-3">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-600">
                                        @if (str_contains($activity->action, 'two_factor'))
                                            <flux:icon.shield-check class="size-4" />
                                        @elseif (str_contains($activity->action, 'passkey') || str_contains($activity->action, 'password'))
                                            <flux:icon.key class="size-4" />
                                        @elseif (str_contains($activity->action, 'login') || str_contains($activity->action, 'logout'))
                                            <flux:icon.arrow-left-start-on-rectangle class="size-4" />
                                        @else
                                            <flux:icon.clock class="size-4" />
                                        @endif
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-zinc-950">{{ $activity->label }}</p>
                                        <p class="mt-1 text-xs text-zinc-500">{{ str($activity->action)->replace('_', ' ')->title() }}</p>
                                        @php
                                            $metadata = collect($activity->metadata ?? [])
                                                ->filter(fn ($value) => filled($value))
                                                ->map(fn ($value, $key) => str($key)->replace('_', ' ')->title().': '.(is_scalar($value) ? (string) $value : json_encode($value)))
                                                ->implode(' / ');
                                        @endphp
                                        @if ($metadata !== '')
                                            <p class="mt-1 truncate text-xs text-zinc-400">{{ $metadata }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($reports->priorityForAction($activity->action) === 'attention')
                                    <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-rose-200">Attention</span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-600">Normal</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $activity->user?->name ?? 'Deleted user' }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $activity->user?->email ?? '-' }}</p>
                            </td>
                            <td class="px-4 py-3 text-zinc-600">{{ $activity->tenant?->company_name ?? 'Platform' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $activity->ip_address ?: '-' }}</td>
                            <td class="px-4 py-3 text-zinc-600">
                                <p>{{ $activity->created_at->format('M j, Y H:i') }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $activity->created_at->diffForHumans() }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button type="button" size="sm" variant="ghost" icon="eye" wire:click="viewActivity({{ $activity->id }})" wire:loading.attr="disabled" wire:target="viewActivity({{ $activity->id }})">
                                    View
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center">
                                <p class="font-medium">No security activity matches this view.</p>
                                <p class="mt-1 text-sm text-zinc-500">Try a wider date range or clear filters.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div>{{ $activities->links() }}</div>

    <flux:modal wire:model.self="showDetailModal" class="md:w-3xl" :dismissible="true">
        @if ($selectedActivity)
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-700">
                        @if (str_contains($selectedActivity->action, 'two_factor'))
                            <flux:icon.shield-check class="size-5" />
                        @elseif (str_contains($selectedActivity->action, 'passkey') || str_contains($selectedActivity->action, 'password'))
                            <flux:icon.key class="size-5" />
                        @elseif (str_contains($selectedActivity->action, 'login') || str_contains($selectedActivity->action, 'logout'))
                            <flux:icon.arrow-left-start-on-rectangle class="size-5" />
                        @else
                            <flux:icon.clock class="size-5" />
                        @endif
                    </span>
                    <div class="min-w-0">
                        <flux:heading size="lg">{{ $selectedActivity->label }}</flux:heading>
                        <p class="mt-1 text-sm text-zinc-500">{{ str($selectedActivity->action)->replace('_', ' ')->title() }} / {{ $selectedActivity->created_at->format('M j, Y H:i:s') }}</p>
                        <div class="mt-3">
                            @if ($reports->priorityForAction($selectedActivity->action) === 'attention')
                                <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-rose-200">Needs attention</span>
                            @else
                                <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-600">Normal activity</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-zinc-200 p-4">
                        <p class="text-xs font-medium uppercase text-zinc-500">Admin</p>
                        <p class="mt-2 font-medium text-zinc-950">{{ $selectedActivity->user?->name ?? 'Deleted user' }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ $selectedActivity->user?->email ?? '-' }}</p>
                    </div>

                    <div class="rounded-lg border border-zinc-200 p-4">
                        <p class="text-xs font-medium uppercase text-zinc-500">Tenant</p>
                        <p class="mt-2 font-medium text-zinc-950">{{ $selectedActivity->tenant?->company_name ?? 'Platform' }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ $selectedActivity->tenant?->slug ?? '-' }}</p>
                    </div>

                    <div class="rounded-lg border border-zinc-200 p-4">
                        <p class="text-xs font-medium uppercase text-zinc-500">IP Address</p>
                        <p class="mt-2 font-mono text-sm text-zinc-950">{{ $selectedActivity->ip_address ?: '-' }}</p>
                    </div>

                    <div class="rounded-lg border border-zinc-200 p-4">
                        <p class="text-xs font-medium uppercase text-zinc-500">Recorded</p>
                        <p class="mt-2 text-sm text-zinc-950">{{ $selectedActivity->created_at->toDayDateTimeString() }}</p>
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4">
                    <p class="text-xs font-medium uppercase text-zinc-500">User Agent</p>
                    <p class="mt-2 break-words text-sm text-zinc-700">{{ $selectedActivity->user_agent ?: '-' }}</p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4">
                    <p class="text-xs font-medium uppercase text-zinc-500">Metadata</p>
                    @if (! empty($selectedActivity->metadata))
                        <pre class="mt-2 max-h-64 overflow-auto rounded-md bg-zinc-950 p-3 text-xs leading-5 text-zinc-100">{{ json_encode($selectedActivity->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @else
                        <p class="mt-2 text-sm text-zinc-500">No additional metadata was recorded.</p>
                    @endif
                </div>

                <div class="flex justify-end">
                    <flux:button type="button" variant="outline" wire:click="closeDetailModal">Close</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
