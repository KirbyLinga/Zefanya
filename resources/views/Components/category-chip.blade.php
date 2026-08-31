{{-- resources/views/Components/category-chip.blade.php
     Usage: @include('Components.category-chip', [
         'label' => 'ELECTRONICS',
         'icon' => 'smartphone',           // Lucide icon name (https://lucide.dev/icons)
     ]) --}}
<div class="div-4">
    <div class="img-wrapper">
        <i data-lucide="{{ $icon }}" width="26" height="26" style="color: #79545c;"></i>
    </div>
    <div class="text-12">{{ $label }}</div>
</div>
