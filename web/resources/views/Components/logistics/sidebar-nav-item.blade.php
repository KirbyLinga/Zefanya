@props(['route' => null, 'icon' => null, 'label' => ''])

@php
    $hasRoute = $route && Route::has($route);
    $pattern = $route && str_ends_with($route, '.index') ? substr($route, 0, -5) . '*' : $route;
    $isActive = $hasRoute && request()->routeIs($pattern);
@endphp

@if ($hasRoute)
    <a
        href="{{ route($route) }}"
        class="flex min-h-10 w-full items-center gap-3 rounded-md px-3 py-2 text-left font-sans text-[13px] font-medium no-underline transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sidebar-active-border)] [.is-collapsed_&]:justify-center [.is-collapsed_&]:gap-0 [.is-collapsed_&]:px-0 {{ $isActive ? 'is-active bg-[var(--sidebar-active-bg)] text-[var(--sidebar-active-text)] font-semibold' : 'text-[var(--sidebar-text)] hover:bg-[var(--sidebar-hover)]' }}"
        aria-label="{{ $label }}"
        data-tooltip="{{ $label }}"
        @if ($isActive) aria-current="page" @endif
    >
        <i data-lucide="{{ $icon }}" class="size-[18px] shrink-0" aria-hidden="true"></i>
        <span class="text-inherit [.is-collapsed_&]:hidden">{{ $label }}</span>
    </a>
@endif
