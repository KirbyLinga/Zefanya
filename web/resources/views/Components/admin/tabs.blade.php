@props(['tabs' => []])

{{--
    Tabs for pages grouped under one sidebar item. Plain links, no JavaScript.

    Each tab: label, then either route (optional params; active defaults to the
    route's name group) or soon => true for a page that does not exist yet.
--}}
<nav data-admin-tabs aria-label="Section pages" class="mb-6 flex flex-wrap gap-x-2 border-b border-neutral-200">
    @foreach ($tabs as $tab)
        @if ($tab['soon'] ?? false)
            <span aria-disabled="true" title="Coming soon" class="-mb-px inline-flex min-h-11 cursor-default items-center gap-2 border-b-2 border-transparent px-3 font-sans text-[13px] font-medium text-neutral-400">
                {{ $tab['label'] }}
                <span class="text-[10px] font-semibold uppercase tracking-wide text-neutral-400">Soon</span>
            </span>
        @else
            @php
                $tabActive = $tab['active'] ?? request()->routeIs(preg_replace('/\.index$/', '.*', $tab['route']));
            @endphp
            <a href="{{ route($tab['route'], $tab['params'] ?? []) }}"
               @if ($tabActive) aria-current="page" @endif
               class="-mb-px inline-flex min-h-11 items-center gap-2 border-b-2 px-3 font-sans text-[13px] no-underline transition-colors {{ $tabActive ? 'border-primary-800 font-semibold text-primary-800' : 'border-transparent font-medium text-neutral-600 hover:text-neutral-950' }}">
                {{ $tab['label'] }}
                @if (($tab['badge'] ?? 0) > 0)
                    <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-secondary-300 px-1.5 text-[10px] font-bold text-neutral-950">{{ $tab['badge'] }}</span>
                @endif
            </a>
        @endif
    @endforeach
</nav>
