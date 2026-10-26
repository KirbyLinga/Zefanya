@extends('Buyer.Layouts.app')
@section('title', $category->label ?? 'Category')
@section('content')
<div class="min-h-screen bg-gradient-to-b from-neutral-50 to-primary-50">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-12">
        {{-- Breadcrumb + header --}}
        <div class="mb-10">
            <div class="flex items-center gap-2 mb-4 text-[13px] text-buyer-ink-soft">
                <a href="{{ route('buyer.products.index') }}" class="hover:text-primary-800 transition-colors">Shop</a>
                <span>/</span>
                <span class="text-buyer-ink font-medium">{{ $category->label }}</span>
            </div>
            <h1 class="font-serif text-[42px] sm:text-[48px] font-normal text-buyer-ink mb-2">{{ $category->label }}</h1>
            <p class="text-[14px] text-buyer-ink-soft">Explore this category</p>
        </div>

        {{-- Top controls: Sort and View toggles --}}
        <div class="mb-8 flex flex-wrap items-center gap-4 justify-between">
            <div class="flex flex-wrap items-center gap-4">
                {{-- Sort dropdown --}}
                <form method="GET" action="{{ route('buyer.categories.show', $category->id) }}" class="relative group" data-category-sort-form>
                    @foreach ($preservedFilters as $name => $value)
                        @if (is_scalar($value))
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <button type="button" data-category-sort-toggle aria-expanded="false" aria-controls="category-sort-menu" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-primary-50 border border-buyer-line text-buyer-ink rounded-lg text-[13px] font-medium transition-colors h-9">
                        <span>Sort by {{ $sortOptions[$sort] }}</span>
                        <i data-lucide="chevron-down" width="16" height="16"></i>
                    </button>
                    <div id="category-sort-menu" data-category-sort-menu class="hidden absolute top-full left-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-buyer-line p-3 z-50">
                        <div class="space-y-1">
                            @foreach ($sortOptions as $value => $label)
                                <label class="flex items-center gap-3 cursor-pointer p-2.5 rounded-lg hover:bg-primary-50">
                                    <input type="radio" name="sort" value="{{ $value }}" @checked($sort === $value) class="accent-primary-800">
                                    <span class="text-[13px] text-buyer-ink {{ $sort === $value ? 'font-medium' : '' }}">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </form>

                {{-- View toggles --}}
                <div class="flex items-center gap-2 border-l border-buyer-line pl-4">
                    <button type="button" data-products-view="grid" aria-label="Grid view" aria-pressed="true" class="inline-flex h-9 w-9 items-center justify-center rounded bg-primary-800 text-white hover:bg-primary-700 transition-colors">
                        <i data-lucide="grid-3x3" width="18" height="18"></i>
                    </button>
                    <button type="button" data-products-view="list" aria-label="List view" aria-pressed="false" class="inline-flex h-9 w-9 items-center justify-center rounded border border-buyer-line text-buyer-ink-soft hover:bg-primary-50 transition-colors">
                        <i data-lucide="list" width="18" height="18"></i>
                    </button>
                </div>
            </div>

            <div class="text-[12px] text-buyer-ink-soft">
                <span class="text-buyer-ink font-medium">{{ $products->total() ?? 0 }}</span> results
            </div>
        </div>

        {{-- Related categories --}}
        <div class="mb-8 relative">
            <div data-category-scroll class="overflow-x-auto scrollbar-hide scroll-smooth">
                <div class="flex gap-3 pb-2">
                    <a href="{{ route('buyer.products.index') }}" 
                       class="px-5 py-2.5 rounded-full bg-white border border-buyer-line text-buyer-ink whitespace-nowrap text-[13px] font-medium hover:bg-primary-50 transition-colors flex-shrink-0">
                        All
                    </a>
                    <button class="px-5 py-2.5 rounded-full bg-primary-800 text-white whitespace-nowrap text-[13px] font-semibold hover:bg-primary-700 transition-colors flex-shrink-0 shadow-sm">
                        {{ $category->label }}
                    </button>
                    @foreach ($categories->where('id', '!=', $category->id) as $cat)
                        <a href="{{ route('buyer.categories.show', $cat->id) }}" 
                           class="px-5 py-2.5 rounded-full bg-white border border-buyer-line text-buyer-ink whitespace-nowrap text-[13px] font-medium hover:bg-primary-50 transition-colors flex-shrink-0">
                            {{ $cat->label }}
                        </a>
                    @endforeach
                </div>
            </div>
            {{-- Scroll fade indicator --}}
            <div class="absolute right-0 top-0 bottom-0 w-12 bg-gradient-to-l from-primary-50 via-primary-50/60 to-transparent pointer-events-none"></div>
        </div>

        {{-- Products grid --}}
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
            <div class="rounded-2xl border-2 border-dashed border-buyer-line bg-primary-50/50 p-12 text-center">
                <i data-lucide="shopping-bag" width="48" height="48" class="mx-auto mb-4 text-buyer-ink-soft"></i>
                <p class="font-serif text-[20px] text-buyer-ink mb-2">No products in this category</p>
                <p class="text-[14px] text-buyer-ink-soft">Check back soon for new items!</p>
            </div>
        @endif
    </div>
</div>

<style>
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
    }
    .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    
    [data-category-scroll] {
        scroll-behavior: smooth;
    }
</style>



@endsection
