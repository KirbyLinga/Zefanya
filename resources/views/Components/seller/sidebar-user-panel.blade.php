@props(['seller' => null])

@php
    $seller ??= auth('seller')->user();
    $initial = strtoupper(substr($seller?->business_name ?? $seller?->fullName() ?? 'S', 0, 1));
    $name = $seller?->business_name ?? $seller?->fullName();
@endphp

<div class="seller-sidebar__seller">
    <div class="seller-sidebar__avatar">
        {{ $initial }}
    </div>
    <div class="seller-sidebar__seller-info">
        <p class="seller-sidebar__seller-name">{{ $name }}</p>
        <p class="seller-sidebar__seller-status">Approved seller</p>
    </div>
</div>
