@props(['provider' => null])

@php
    $provider ??= auth('logistics')->user();
    $initial  = strtoupper(substr($provider?->business_name ?? $provider?->fullName() ?? 'L', 0, 1));
    $name     = $provider?->business_name ?? $provider?->fullName();
    $tooltip  = $name . ' · Operations hub';
@endphp

<div
    class="flex items-center gap-3 px-3 pb-2 [.is-collapsed_&]:justify-center [.is-collapsed_&]:px-0"
    data-tooltip="{{ $tooltip }}"
    aria-label="{{ $tooltip }}"
>
    <div class="w-[36px] h-[36px] rounded-full bg-[var(--sidebar-active-bg)] text-[var(--sidebar-active-text)] flex items-center justify-center font-sans font-semibold text-[14px] flex-shrink-0 min-w-[40px] min-h-[40px]">
        {{ $initial }}
    </div>
    <div class="min-w-0 [.is-collapsed_&]:hidden">
        <p class="font-sans text-[13px] font-semibold text-[var(--sidebar-text)] whitespace-nowrap overflow-hidden text-ellipsis">{{ $name }}</p>
        <p class="font-sans text-[11.5px] text-[var(--sidebar-muted)]">Operations hub</p>
    </div>
</div>
