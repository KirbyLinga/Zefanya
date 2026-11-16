{{-- Mirrors x-logistics.sidebar-nav: small uppercase group labels, flat items. --}}
@props(['sections' => []])

@foreach ($sections as $section)
    <div class="flex flex-col gap-1 {{ ! empty($section['label']) ? 'mt-4' : '' }}">
        @if (! empty($section['label']))
            <p class="px-3 pb-1 font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--sidebar-muted)] [.is-collapsed_&]:hidden">{{ $section['label'] }}</p>
        @endif
        @foreach ($section['items'] as $item)
            <x-seller.sidebar-nav-item :route="$item['route'] ?? null" :icon="$item['icon'] ?? null" :label="$item['label'] ?? ''" :badge="$item['badge'] ?? 0" :badge-noun="$item['badgeNoun'] ?? 'new'" :active="$item['active'] ?? []" :primary="$item['primary'] ?? false" />
        @endforeach
    </div>
@endforeach
