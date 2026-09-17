@props(['route' => null, 'icon' => null, 'label' => ''])

@php
    // Resolve the route once per item. route() is a global helper and does
    // not conflict with the $route prop / $hasRoute locals here.
    $hasRoute = $route ? \Illuminate\Support\Facades\Route::has($route) : false;
    $isActive = $hasRoute && request()->routeIs($route . '*');
    $href = $hasRoute ? route($route) : '#';
@endphp

<a
    href="{{ $href }}"
    class="seller-nav__link {{ $isActive ? 'is-active' : '' }}"
    @if ($isActive) aria-current="page" @endif
>
    <i data-lucide="{{ $icon }}" class="seller-nav__icon"></i>
    <span>{{ $label }}</span>
</a>
