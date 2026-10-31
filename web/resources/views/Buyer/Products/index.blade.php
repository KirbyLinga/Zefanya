@extends('Buyer.Layouts.app')
@section('title', 'Shop')
@section('content')
<div class="min-h-screen bg-gradient-to-b from-neutral-50 to-primary-50">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-12">


        {{-- Top controls: Sort and View toggles --}}
        <div class="mb-8 flex items-center gap-4 justify-between">
            <div class="flex items-center gap-4">
                {{-- Sort dropdown --}}
                <div class="relative">
                    @php
                        $sortOptions = ['relevance' => 'Relevance', 'price_asc' => 'Price: Low to High', 'price_desc' => 'Price: High to Low', 'newest' => 'Newest', 'oldest' => 'Oldest'];
                        $currentSort = is_string(request('sort')) ? request('sort') : 'relevance';
                        $currentLabel = $sortOptions[$currentSort] ?? 'Relevance';
                    @endphp
                    <button type="button"
                            class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-primary-50 border border-buyer-line text-buyer-ink rounded-lg text-[13px] font-medium transition-colors h-9"
                            data-sort-toggle>
                        <span>Sort: {{ $currentLabel }}</span>
                        <i data-lucide="chevron-down" width="16" height="16"></i>
                    </button>
                    <div class="hidden absolute top-full left-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-buyer-line p-3 z-50"
                         data-sort-menu>
                        <div class="space-y-1">
                            @foreach ($sortOptions as $value => $label)
                                <a href="{{ request()->fullUrlWithQuery(['sort' => $value, 'page' => 1]) }}"
                                   class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-primary-50 transition-colors @if ($currentSort === $value) bg-primary-50 @endif">
                                    <i data-lucide="check" width="16" height="16"
                                       class="@if ($currentSort === $value) text-primary-800 @else text-transparent @endif"></i>
                                    <span class="text-[13px] text-buyer-ink @if ($currentSort === $value) font-semibold @endif">{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- View toggles --}}
                <div class="flex items-center gap-2 border-l border-buyer-line pl-4">
                    <button type="button"
                            data-products-view="grid" aria-pressed="true" aria-label="Grid view"
                            class="inline-flex h-9 w-9 items-center justify-center rounded bg-primary-800 text-white hover:bg-primary-700 transition-colors">
                        <i data-lucide="grid-3x3" width="18" height="18"></i>
                    </button>
                    <button type="button"
                            data-products-view="list" aria-pressed="false" aria-label="List view"
                            class="inline-flex h-9 w-9 items-center justify-center rounded border border-buyer-line text-buyer-ink-soft hover:bg-primary-50 transition-colors">
                        <i data-lucide="list" width="18" height="18"></i>
                    </button>
                </div>
            </div>

            <div class="text-[12px] text-buyer-ink-soft">
                <span class="text-buyer-ink font-medium">{{ $products->total() }}</span> @if ($products->total() === 1) result @else results @endif
            </div>
        </div>

        @include('Buyer.Search._price-filter', ['action' => route('buyer.products.index')])

        {{-- Category pills (horizontal scroll) --}}
        <div class="mb-8 relative group/category-row">
            {{-- Left scroll arrow --}}
            <button type="button"
                    data-category-scroll-left
                    aria-label="Scroll categories left"
                    class="absolute -left-12 top-1/2 -translate-y-1/2 hidden md:inline-flex h-9 w-9 items-center justify-center rounded-full border border-buyer-line bg-white text-buyer-ink-soft hover:text-buyer-ink hover:bg-primary-50 transition-colors shadow-sm z-10"
                    style="display: none;">
                <i data-lucide="chevron-left" width="18" height="18"></i>
            </button>

            {{-- Category scroll track --}}
            <div data-category-scroll 
                 class="overflow-x-auto whitespace-nowrap scrollbar-hide scroll-smooth snap-x snap-mandatory"
                 style="scroll-behavior: smooth; padding-bottom: 8px;">
                <div class="inline-flex gap-3">
                    <a href="{{ request()->fullUrlWithQuery(['category' => null, 'page' => 1]) }}"
                       data-category-pill
                       class="px-5 py-2.5 rounded-full @if (!request('category')) bg-primary-800 text-white shadow-sm @else bg-white border border-buyer-line text-buyer-ink hover:bg-primary-50 @endif text-[13px] font-semibold transition-colors inline-flex flex-shrink-0 snap-start focus:outline-none focus:ring-2 focus:ring-primary-800 focus:ring-offset-2 rounded-full">
                        All
                    </a>
                    @foreach ($categories as $category)
                        <a href="{{ request()->fullUrlWithQuery(['category' => $category->id, 'page' => 1]) }}"
                           data-category-pill
                           class="px-5 py-2.5 rounded-full @if (request('category') == $category->id) bg-primary-800 text-white shadow-sm @else bg-white border border-buyer-line text-buyer-ink hover:bg-primary-50 @endif text-[13px] font-medium transition-colors inline-flex flex-shrink-0 snap-start focus:outline-none focus:ring-2 focus:ring-primary-800 focus:ring-offset-2 rounded-full">
                            {{ $category->label }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Right scroll arrow --}}
            <button type="button"
                    data-category-scroll-right
                    aria-label="Scroll categories right"
                    class="absolute -right-12 top-1/2 -translate-y-1/2 hidden md:inline-flex h-9 w-9 items-center justify-center rounded-full border border-buyer-line bg-white text-buyer-ink-soft hover:text-buyer-ink hover:bg-primary-50 transition-colors shadow-sm z-10">
                <i data-lucide="chevron-right" width="18" height="18"></i>
            </button>

            {{-- Left fade overlay (shown when scrolled) --}}
            <div data-category-fade-left
                 class="absolute left-0 top-0 bottom-0 w-12 bg-gradient-to-r from-neutral-50 via-neutral-50/60 to-transparent pointer-events-none hidden"
                 style="background-color: transparent;"></div>

            {{-- Right fade overlay (shown when more content) --}}
            <div data-category-fade-right
                 class="absolute right-0 top-0 bottom-0 w-12 bg-gradient-to-l from-neutral-50 via-neutral-50/60 to-transparent pointer-events-none"></div>
        </div>

        {{-- Active filters chips --}}
        @if ($searchTerm || request('category') || request('sort') !== 'relevance')
            <div class="mb-8">
                @if ($searchTerm)
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-[16px] font-semibold text-buyer-ink">Results for "<span class="text-primary-800">{{ $searchTerm }}</span>"</h2>
                        <a href="{{ route('buyer.products.index') }}" class="text-[13px] font-medium text-primary-800 hover:text-primary-900">Clear</a>
                    </div>
                @endif
                
                <div class="flex flex-wrap gap-2">
                    @if ($searchTerm)
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary-100 text-primary-800 text-[12px] font-medium">
                            Search: "{{ $searchTerm }}"
                            <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => 1]) }}"
                               aria-label="Remove search filter"
                               class="hover:text-primary-900">
                                <i data-lucide="x" width="14" height="14"></i>
                            </a>
                        </div>
                    @endif

                @if (request('category'))
                    @php
                        $selectedCat = $categories->firstWhere('id', request('category'));
                    @endphp
                    @if ($selectedCat)
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary-100 text-primary-800 text-[12px] font-medium">
                            Category: {{ $selectedCat->label }}
                            <a href="{{ request()->fullUrlWithQuery(['category' => null, 'page' => 1]) }}"
                               aria-label="Remove category filter"
                               class="hover:text-primary-900">
                                <i data-lucide="x" width="14" height="14"></i>
                            </a>
                        </div>
                    @endif
                @endif

                @if (request('sort') !== 'relevance')
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary-100 text-primary-800 text-[12px] font-medium">
                        Sort: {{ $currentLabel }}
                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'relevance', 'page' => 1]) }}"
                           aria-label="Remove sort filter"
                           class="hover:text-primary-900">
                            <i data-lucide="x" width="14" height="14"></i>
                        </a>
                    </div>
                @endif

                <a href="{{ route('buyer.products.index') }}"
                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-buyer-line/50 text-buyer-ink-soft hover:bg-buyer-line text-[12px] font-medium transition-colors">
                    <i data-lucide="x" width="14" height="14"></i>
                    Clear all
                </a>
                </div>
            </div>
        @endif

        {{-- Products grid or empty state --}}
        @if ($products->isNotEmpty())
            <div data-products-grid data-layout="grid" class="group/products grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 mb-12">
                @foreach ($products as $product)
                    @include('Components.buyer.product-card', [
                        'product' => $product,
                        'url' => route('buyer.products.show', $product->id),
                    ])
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="flex justify-center mt-16">
                {{ $products->links() }}
            </div>
        @else
            {{-- Empty state --}}
            <div class="rounded-2xl border-2 border-dashed border-buyer-line bg-primary-50/50 p-12 text-center">
                <i data-lucide="shopping-bag" width="48" height="48" class="mx-auto mb-4 text-buyer-ink-soft"></i>
                <p class="font-serif text-[20px] text-buyer-ink mb-2">No products found</p>
                @include('Buyer.Search._empty-hint', [
                    'term' => $searchTerm,
                    'suggestionUrl' => $suggestion ? route('buyer.products.index', ['q' => $suggestion]) : null,
                ])
                <a href="{{ route('buyer.products.index') }}"
                   class="inline-flex items-center gap-2 px-6 py-2 rounded-lg bg-primary-800 text-white hover:bg-primary-700 transition-colors font-semibold text-[13px]">
                    <i data-lucide="rotate-ccw" width="16" height="16"></i>
                    Clear filters
                </a>
            </div>
        @endif
    </div>
</div>

<style>
    [data-category-scroll] {
        scroll-behavior: smooth;
    }
</style>

@push('scripts')
    @vite(['resources/js/buyer/category-scroll.js'])
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Sort dropdown toggle
            const sortToggle = document.querySelector('[data-sort-toggle]');
            const sortMenu = document.querySelector('[data-sort-menu]');

            if (sortToggle && sortMenu) {
                sortToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    sortMenu.classList.toggle('hidden');
                });

                // Close menu when clicking outside
                document.addEventListener('click', function () {
                    sortMenu.classList.add('hidden');
                });

                // Prevent closing when clicking inside the menu
                sortMenu.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }


        });
    </script>
@endpush

@endsection
