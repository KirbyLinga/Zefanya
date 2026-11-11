{{-- `active` lists extra route patterns (one item can cover several tabbed pages);
     `primary` outlines the main action so it stands out without new colours. --}}
@props(['route' => null, 'icon' => null, 'label' => '', 'badge' => 0, 'active' => [], 'primary' => false])

@php
    $hasRoute = $route && Route::has($route);
    $pattern = $route && str_ends_with($route, '.index') ? substr($route, 0, -5) . '*' : $route;
    $isActive = $hasRoute && request()->routeIs($pattern, ...$active);
@endphp

@if ($hasRoute)
    <a
        href="{{ route($route) }}"
        class="flex min-h-10 w-full items-center gap-3 rounded-md px-3 py-2 text-left font-sans text-[13px] font-medium no-underline transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sidebar-active-border)] [.is-collapsed_&]:justify-center [.is-collapsed_&]:gap-0 [.is-collapsed_&]:px-0 {{ $primary ? 'border border-[var(--sidebar-active-border)] font-semibold' : '' }} {{ $isActive ? 'is-active bg-[var(--sidebar-active-bg)] text-[var(--sidebar-active-text)] font-semibold' : 'text-[var(--sidebar-text)] hover:bg-[var(--sidebar-hover)]' }}"
        aria-label="{{ $badge > 0 ? $label.', '.$badge.' unread' : $label }}"
        data-tooltip="{{ $label }}"
        @if ($isActive) aria-current="page" @endif
    >
        <span class="relative shrink-0">
            <i data-lucide="{{ $icon }}" class="size-[18px]" aria-hidden="true"></i>
            @if ($badge > 0)
                <span class="absolute -right-1 -top-1 hidden size-2 rounded-full bg-red-600 [.is-collapsed_&]:block" aria-hidden="true"></span>
            @endif
        </span>
        <span class="flex-1 text-inherit [.is-collapsed_&]:hidden">{{ $label }}</span>
        @if ($badge > 0)
            <span class="rounded-full bg-red-600 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white [.is-collapsed_&]:hidden" aria-hidden="true">{{ $badge > 99 ? '99+' : $badge }}</span>
        @endif
    </a>
@endif
