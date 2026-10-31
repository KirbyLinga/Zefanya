@props(['sections' => []])

@foreach ($sections as $section)
    @php
        $collapsible = $section['collapsible'] ?? false;
        $active = collect($section['items'])->contains(function ($item) {
            $route = $item['route'] ?? '';
            $pattern = str_ends_with($route, '.index') ? substr($route, 0, -5) . '*' : $route;
            return $route !== '' && request()->routeIs($pattern);
        });
        $groupId = 'seller-nav-group-' . $loop->index;
        $groupBadge = collect($section['items'])->sum(fn ($item) => $item['badge'] ?? 0);
    @endphp

    @if ($collapsible)
        {{-- Sharing a name keeps one group open at a time, so the list stays short. --}}
        <details class="group" name="seller-nav" data-nav-group @if ($active) open @endif>
            <summary
                class="flex items-center gap-3 px-3 py-2 rounded-md border-l-[3px] border-l-transparent font-sans text-[13.5px] font-medium cursor-pointer list-none opacity-70 transition duration-150 hover:bg-[var(--sidebar-hover)] hover:text-[var(--sidebar-text)] hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sidebar-active-border)] [&::-webkit-details-marker]:hidden [.is-collapsed_&]:justify-center [.is-collapsed_&]:px-0 [.is-collapsed_&]:gap-0 [.is-collapsed_&]:min-w-[40px] [.is-collapsed_&]:min-h-[40px] {{ $active ? 'bg-[var(--sidebar-hover)] opacity-100' : '' }}"
                aria-label="{{ $section['label'] }}"
                aria-expanded="{{ $active ? 'true' : 'false' }}"
                aria-controls="{{ $groupId }}"
                data-tooltip="{{ $section['label'] }}"
            >
                <i data-lucide="{{ $section['icon'] }}" class="w-[18px] h-[18px] flex-shrink-0" aria-hidden="true"></i>
                <span class="flex-1 text-inherit [.is-collapsed_&]:hidden">{{ $section['label'] }}</span>
                @if ($groupBadge > 0)
                    <span class="bg-red-500 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 group-open:hidden [.is-collapsed_&]:hidden">{{ min($groupBadge, 9) }}</span>
                @endif
                <i data-lucide="chevron-down" class="w-3.5 h-3.5 flex-shrink-0 transition-transform group-open:rotate-180 [.is-collapsed_&]:hidden" aria-hidden="true"></i>
            </summary>
            <div id="{{ $groupId }}" class="mt-1 ml-5 pl-2 flex flex-col gap-1 border-l border-[var(--sidebar-border)] [.is-collapsed_&]:hidden">
                @foreach ($section['items'] as $item)
                    {{-- Bound (:label) so a label containing "&" is escaped exactly once. --}}
                    <x-seller.sidebar-nav-item
                        route="{{ $item['route'] ?? null }}"
                        icon="{{ $item['icon'] ?? null }}"
                        :label="$item['label'] ?? ''"
                        :badge="$item['badge'] ?? null"
                    />
                @endforeach
            </div>
        </details>
    @else
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
                    :badge="$item['badge'] ?? null"
                />
            @endforeach
        </div>
    @endif
@endforeach
