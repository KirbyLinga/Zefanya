@props(['label', 'icon', 'route' => null, 'active' => [], 'badge' => 0])

{{-- One sidebar row. With no route the page is not built yet: disabled text, never a link. --}}
@php
    $row = 'flex min-h-11 items-center gap-3 rounded-md px-3 py-2 font-sans text-[13px] font-medium [&>span]:text-inherit [&>i]:size-[18px] [.is-collapsed_&]:justify-center [.is-collapsed_&]:gap-0 [.is-collapsed_&]:px-0 [.is-collapsed_&>span]:hidden';
    $isActive = $route !== null && request()->routeIs(...($active ?: [$route]));
@endphp
@if ($route === null)
    <span data-admin-nav-item aria-disabled="true" title="{{ $label }}: coming soon" class="{{ $row }} cursor-default text-[var(--sidebar-muted)]">
        <i data-lucide="{{ $icon }}" width="20" height="20" class="shrink-0" aria-hidden="true"></i>
        <span>{{ $label }}</span>
        <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
    </span>
@else
    <a data-admin-nav-item href="{{ route($route) }}" aria-label="{{ $label }}" title="{{ $label }}" @if ($isActive) aria-current="page" @endif
       class="{{ $row }} text-[var(--sidebar-text)] no-underline transition-colors hover:bg-[var(--sidebar-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sidebar-active-border)] {{ $isActive ? '!bg-[var(--sidebar-active-bg)] !text-[var(--sidebar-active-text)] !font-semibold' : '' }}">
        <i data-lucide="{{ $icon }}" width="20" height="20" class="shrink-0" aria-hidden="true"></i>
        <span>{{ $label }}</span>
        @if ($badge > 0)
            <span class="ml-auto inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 font-sans text-[10px] font-bold text-primary-800! {{ $isActive ? 'bg-white' : 'bg-secondary-300' }}">{{ $badge }}</span>
        @endif
    </a>
@endif
