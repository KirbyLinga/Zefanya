{{-- Rider-controlled online/offline switch. Only meaningful while the account can take jobs. --}}
@php
    $assignable = in_array($rider->status, \App\Models\Rider\Rider::ASSIGNABLE_STATUSES, true);
@endphp
@if (! $assignable)
    <span class="font-sans text-[12px] text-[var(--text-muted)]">—</span>
@else
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-sans text-[11.5px] font-semibold {{ $rider->isOnline() ? 'bg-emerald-50 text-emerald-800' : 'bg-neutral-100 text-neutral-700' }}">
        <span class="h-1.5 w-1.5 rounded-full {{ $rider->isOnline() ? 'bg-emerald-600' : 'bg-neutral-400' }}" aria-hidden="true"></span>
        {{ $rider->isOnline() ? 'Online' : 'Offline' }}
    </span>
    @if ($rider->online_changed_at)
        <p class="mt-0.5 font-sans text-[11px] text-[var(--text-muted)]">since {{ $rider->online_changed_at->format('M j, g:i A') }}</p>
    @endif
@endif
