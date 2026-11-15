@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between gap-4 px-4 py-6 md:px-8">
        {{-- Results Summary --}}
        <div class="text-sm font-medium text-[var(--text-muted)]">
            @if ($paginator->firstItem())
                <span class="text-[var(--text-ink)] font-semibold">{{ $paginator->firstItem() }}</span>
                <span>–</span>
                <span class="text-[var(--text-ink)] font-semibold">{{ $paginator->lastItem() }}</span>
                <span>of</span>
                <span class="text-[var(--text-ink)] font-semibold">{{ $paginator->total() }}</span>
            @endif
        </div>

        {{-- Pagination Elements --}}
        <div class="flex items-center gap-1">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <button disabled aria-disabled="true" tabindex="-1" aria-label="Previous page" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--text-muted)] bg-[var(--bg-surface)] text-[var(--text-muted)] cursor-not-allowed">
                    <i data-lucide="chevron-left" width="18" height="18" aria-hidden="true"></i>
                </button>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--text-muted)] bg-[var(--bg-surface)] text-[var(--text-ink)] transition-colors hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
                    <i data-lucide="chevron-left" width="18" height="18" aria-hidden="true"></i>
                </a>
            @endif

            {{-- Pagination Links --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="inline-flex h-9 w-9 items-center justify-center text-[var(--text-muted)]">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <button type="button" aria-current="page" aria-label="Current page, {{ $page }}" disabled aria-disabled="true" tabindex="-1" class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--btn-accent)] text-[var(--btn-accent-contrast)] font-semibold text-sm">
                                {{ $page }}
                            </button>
                        @else
                            <a href="{{ $url }}" aria-label="Go to page {{ $page }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--text-muted)] bg-[var(--bg-surface)] text-[var(--text-ink)] font-medium text-sm transition-colors hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--text-muted)] bg-[var(--bg-surface)] text-[var(--text-ink)] transition-colors hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
                    <i data-lucide="chevron-right" width="18" height="18" aria-hidden="true"></i>
                </a>
            @else
                <button disabled aria-disabled="true" tabindex="-1" aria-label="Next page" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--text-muted)] bg-[var(--bg-surface)] text-[var(--text-muted)] cursor-not-allowed">
                    <i data-lucide="chevron-right" width="18" height="18" aria-hidden="true"></i>
                </button>
            @endif
        </div>
    </nav>
@endif
