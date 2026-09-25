@props(['sections' => []])

@foreach ($sections as $section)
    <div class="flex flex-col gap-1">
        @if (! empty($section['label']))
            <p class="font-sans text-[11px] font-semibold tracking-[0.8px] uppercase text-[var(--sidebar-muted)] px-3 mb-1 [.is-collapsed_&]:hidden">{{ $section['label'] }}</p>
        @endif

        @foreach ($section['items'] as $item)
            {{-- Bound (:label) so a label containing "&" is escaped exactly once. --}}
            <x-seller.sidebar-nav-item
                route="{{ $item['route'] ?? null }}"
                icon="{{ $item['icon'] ?? null }}"
                :label="$item['label'] ?? ''"
            />
        @endforeach
    </div>
@endforeach
