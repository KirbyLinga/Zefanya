@props(['sections' => []])

@foreach ($sections as $section)
    <div class="seller-nav__section">
        @if (! empty($section['label']))
            <p class="seller-nav__label">{{ $section['label'] }}</p>
        @endif

        @foreach ($section['items'] as $item)
            <x-seller.sidebar-nav-item
                route="{{ $item['route'] ?? null }}"
                icon="{{ $item['icon'] ?? null }}"
                label="{{ $item['label'] ?? '' }}"
            />
        @endforeach
    </div>
@endforeach
