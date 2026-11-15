@php
    [$icon, $ring] = match ($event->kind) {
        'status' => ['arrow-right-left', 'border-blue-500 text-blue-600'],
        'assignment' => ['bike', 'border-amber-500 text-amber-600'],
        default => ['file-text', 'border-neutral-400 text-neutral-500'],
    };
@endphp
<li class="relative text-sm">
    <span class="absolute -left-[31px] top-0 flex h-6 w-6 items-center justify-center rounded-full border-2 bg-white {{ $ring }}"><i data-lucide="{{ $icon }}" width="12" height="12" aria-hidden="true"></i></span>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4">
        <p class="font-semibold">@if ($event->kind === 'status'){{ $event->from_status }} → {{ $event->to_status }}@elseif ($event->kind === 'assignment'){{ ucfirst($event->to_status) }} Rider assigned: {{ $event->subject ?? 'Courier no longer available' }}@else{{ \Illuminate\Support\Str::headline($event->to_status) }}@endif</p>
        <p class="text-xs tabular-nums text-neutral-500">{{ manila($event->created_at, 'Y-m-d H:i:s') }}</p>
    </div>
    <p class="mt-0.5 text-xs text-neutral-600">{{ $monitor->actor($event->actor_type, $event->actor_id, $event->actor_name) }} · Source: {{ $event->source ?? $event->kind }}</p>
    @if ($event->note)<p class="mt-1 line-clamp-2 whitespace-pre-line rounded-lg bg-neutral-50 px-3 py-1.5 text-xs text-neutral-700" title="{{ $event->note }}">{{ $event->note }}</p>@endif
</li>
