{{-- resources/views/Components/category-chip.blade.php
     Usage: @include('Components.category-chip', [
         'label' => 'Electronics',
         'icon' => 'smartphone',           // Lucide icon name
         'href' => route('buyer.home'),    // optional
     ]) --}}
@php
    $href = $href ?? '#categories';
@endphp
<a href="{{ $href }}" class="flex min-w-[92px] flex-1 flex-col items-center gap-2 rounded-[22px] bg-white px-3 py-3.5 text-center shadow-[0_2px_10px_-4px_rgba(58,37,41,0.10)] transition-shadow duration-150 hover:shadow-[0_6px_18px_-6px_rgba(58,37,41,0.18)] sm:min-w-0">
    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-secondary-100 text-primary-800">
        <i data-lucide="{{ $icon }}" width="22" height="22"></i>
    </span>
    <span class="line-clamp-2 text-[11px] leading-tight font-semibold text-buyer-ink">{{ $label }}</span>
</a>
