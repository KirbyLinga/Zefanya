@props(['sections' => []])

@foreach ($sections as $section)
    @php
        $collapsible = $section['collapsible'] ?? false;
        $active = collect($section['items'])->contains(function ($item) {
            $route = $item['route'] ?? '';
            $pattern = str_ends_with($route, '.index') ? substr($route, 0, -5) . '*' : $route;
            return $route !== '' && request()->routeIs($pattern);
        });
        $groupId = 'logistics-nav-group-' . $loop->index;
    @endphp

    @if ($collapsible)
        <details class="group" data-nav-group @if ($active) open @endif>
            <summary
                class="flex min-h-10 cursor-pointer list-none items-center gap-3 rounded-md px-3 py-2 font-sans text-[13px] font-medium text-[var(--sidebar-text)] transition-colors hover:bg-[var(--sidebar-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sidebar-active-border)] [&::-webkit-details-marker]:hidden [.is-collapsed_&]:justify-center [.is-collapsed_&]:gap-0 [.is-collapsed_&]:px-0 {{ $active ? 'bg-[var(--sidebar-hover)]' : '' }}"
                aria-label="{{ $section['label'] }}"
                aria-expanded="{{ $active ? 'true' : 'false' }}"
                aria-controls="{{ $groupId }}"
                data-tooltip="{{ $section['label'] }}"
            >
                <i data-lucide="{{ $section['icon'] }}" class="size-[18px] shrink-0" aria-hidden="true"></i>
                <span class="flex-1 text-inherit [.is-collapsed_&]:hidden">{{ $section['label'] }}</span>
                <i data-lucide="chevron-down" class="size-3.5 shrink-0 transition-transform group-open:rotate-180 [.is-collapsed_&]:hidden" aria-hidden="true"></i>
            </summary>
            <div id="{{ $groupId }}" class="mt-1 flex flex-col gap-1 border-l border-[var(--sidebar-border)] pl-2 ml-5 [.is-collapsed_&]:hidden">
                @foreach ($section['items'] as $item)
                    <x-logistics.sidebar-nav-item :route="$item['route'] ?? null" :icon="$item['icon'] ?? null" :label="$item['label'] ?? ''" />
                @endforeach
            </div>
        </details>
    @else
        <div class="flex flex-col gap-1 {{ !empty($section['label']) ? 'mt-4' : '' }}">
            @if (!empty($section['label']))
                <p class="px-3 pb-1 font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--sidebar-muted)] [.is-collapsed_&]:hidden">{{ $section['label'] }}</p>
            @endif
            @foreach ($section['items'] as $item)
                <x-logistics.sidebar-nav-item :route="$item['route'] ?? null" :icon="$item['icon'] ?? null" :label="$item['label'] ?? ''" />
            @endforeach
        </div>
    @endif
@endforeach
