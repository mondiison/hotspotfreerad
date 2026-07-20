@if ($usage)
    <section class="rounded-lg border {{ $usage['can_create'] ? 'border-zinc-200 bg-white' : 'border-amber-200 bg-amber-50' }} p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium {{ $usage['can_create'] ? 'text-zinc-500' : 'text-amber-700' }}">Platform allowance</p>
                <h2 class="mt-1 text-base font-semibold text-zinc-950">{{ $usage['plan_name'] }}</h2>
                <p class="mt-1 text-sm {{ $usage['can_create'] ? 'text-zinc-500' : 'text-amber-700' }}">{{ $usage['message'] }}</p>
            </div>

            <span class="w-fit rounded-full {{ $usage['can_create'] ? 'bg-zinc-100 text-zinc-700' : 'bg-amber-100 text-amber-800' }} px-3 py-1 text-sm font-medium">
                {{ $usage['status'] }}
            </span>
        </div>

        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
            <div class="rounded-md bg-white/70 p-3">
                <dt class="text-zinc-500">Used</dt>
                <dd class="mt-1 font-semibold text-zinc-950">{{ number_format($usage['used']) }} {{ $usage['resource_label'] }}</dd>
            </div>
            <div class="rounded-md bg-white/70 p-3">
                <dt class="text-zinc-500">Plan limit</dt>
                <dd class="mt-1 font-semibold text-zinc-950">{{ $usage['limit_label'] }}</dd>
            </div>
            <div class="rounded-md bg-white/70 p-3">
                <dt class="text-zinc-500">Remaining</dt>
                <dd class="mt-1 font-semibold text-zinc-950">{{ $usage['remaining_label'] }}</dd>
            </div>
        </dl>

        @if (! $usage['can_create'])
        <a href="{{ route('admin.billing.index') }}" wire:navigate class="mt-4 inline-flex rounded-md bg-zinc-950 px-3 py-2 text-sm font-medium text-white">
                Manage billing
            </a>
        @endif
    </section>
@endif
