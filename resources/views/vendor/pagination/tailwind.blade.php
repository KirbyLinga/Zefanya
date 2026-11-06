@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between gap-4 px-4 py-6 md:px-8">
        {{-- Results Summary --}}
        <div class="text-sm font-medium text-buyer-ink-soft">
            @if ($paginator->firstItem())
                <span class="text-buyer-ink font-semibold">{{ $paginator->firstItem() }}</span>
                <span>–</span>
                <span class="text-buyer-ink font-semibold">{{ $paginator->lastItem() }}</span>
                <span>of</span>
                <span class="text-buyer-ink font-semibold">{{ $paginator->total() }}</span>
            @endif
        </div>

        {{-- Pagination Elements --}}
        <div class="flex items-center gap-1">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <button disabled aria-disabled="true" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-buyer-line bg-white text-buyer-ink-soft cursor-not-allowed opacity-50">
                    <i data-lucide="chevron-left" width="18" height="18"></i>
                </button>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-buyer-line bg-white text-buyer-ink hover:bg-primary-50 transition-colors">
                    <i data-lucide="chevron-left" width="18" height="18"></i>
                </a>
            @endif

            {{-- Pagination Links --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="inline-flex h-9 w-9 items-center justify-center text-buyer-ink-soft">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <button aria-current="page" disabled aria-disabled="true" class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-primary-800 text-white font-semibold text-sm">
                                {{ $page }}
                            </button>
                        @else
                            <a href="{{ $url }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-buyer-line bg-white text-buyer-ink font-medium text-sm hover:bg-primary-50 transition-colors">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-buyer-line bg-white text-buyer-ink hover:bg-primary-50 transition-colors">
                    <i data-lucide="chevron-right" width="18" height="18"></i>
                </a>
            @else
                <button disabled aria-disabled="true" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-buyer-line bg-white text-buyer-ink-soft cursor-not-allowed opacity-50">
                    <i data-lucide="chevron-right" width="18" height="18"></i>
                </button>
            @endif
        </div>
    </nav>
@endif
