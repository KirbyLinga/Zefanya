@props(['route' => null, 'icon' => null, 'label' => ''])

@php
    $hasRoute = $route ? \Illuminate\Support\Facades\Route::has($route) : false;
    $isActive = $hasRoute && request()->routeIs($route . '*');
    $href = $hasRoute ? route($route) : '#';

    $classes = 'flex items-center gap-3 px-3 py-2 rounded-md border-l-[3px] border-l-transparent '
        . 'font-sans text-[13.5px] font-medium no-underline '
        . 'w-full text-left cursor-pointer opacity-70 '
        . 'transition duration-150 '
        . 'hover:bg-[var(--sidebar-hover)] hover:text-[var(--sidebar-text)] hover:border-l-[var(--sidebar-active-border)] '
        . 'hover:opacity-100 hover:translate-x-[2px] '
        . '[&.is-active]:border-l-[var(--sidebar-active-border)] [&.is-active]:opacity-100 '
        . '[.is-collapsed_&]:justify-center [.is-collapsed_&]:px-0 [.is-collapsed_&]:gap-0 [.is-collapsed_&]:min-w-[40px] [.is-collapsed_&]:min-h-[40px] ';
    if ($isActive) {
        $classes .= 'is-active bg-[var(--sidebar-active-bg)] text-[var(--sidebar-active-text)] font-semibold';
    }
@endphp

<a
    href="{{ $href }}"
    class="{{ $classes }}"
    aria-label="{{ $label }}"
    data-tooltip="{{ $label }}"
    @if ($isActive) aria-current="page" @endif
>
    <i data-lucide="{{ $icon }}" class="w-[18px] h-[18px] flex-shrink-0"></i>
    {{-- Visually hidden in collapsed state via sp-sidebar-label class (overflow:hidden + width:0).
         Kept in DOM so aria-label on the <a> is the accessible name; the span is aria-hidden. --}}
    <span class="text-inherit [.is-collapsed_&]:hidden" aria-hidden="true">{{ $label }}</span>
</a>
