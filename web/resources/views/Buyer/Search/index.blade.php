@extends('Buyer.Layouts.app')
@section('title', 'Search Results')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        {{-- Search header --}}
        <div class="mb-8">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink">Search Results</h1>
            @if ($query)
                <p class="mt-2 text-[14px] text-buyer-ink-soft">
                    Results for "<span class="font-semibold">{{ $query }}</span>"
                </p>
            @endif
        </div>

        @if ($query !== '' && ! $tooShort)
            @include('Buyer.Search._price-filter', ['action' => route('buyer.search.index')])
        @endif

        {{-- Results --}}
        @if ($products->isNotEmpty() || $categories->isNotEmpty() || $stores->isNotEmpty())
            @if($stores->isNotEmpty())
                <section class="mb-12" aria-label="Matching stores">
                    <h2 class="mb-4 font-serif text-xl text-buyer-ink">Stores</h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($stores as $store)
                            <a href="{{ route('buyer.stores.show', $store) }}" class="flex items-start gap-4 rounded-xl border border-buyer-line bg-white p-4">
                                @if($store->store_logo_path)<img src="{{ Storage::disk('public')->url($store->store_logo_path) }}" alt="{{ $store->business_name }} logo" class="size-16 shrink-0 rounded-lg object-contain">@endif
                                <div class="min-w-0"><h3 class="break-words font-semibold text-buyer-ink">{{ $store->business_name }}</h3><p class="mt-2 line-clamp-3 break-words text-sm text-buyer-ink-soft">{{ $store->store_description }}</p></div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            {{-- Categories (if any) --}}
            @if ($categories->isNotEmpty())
                <div class="mb-12">
                    <h2 class="font-serif text-[20px] text-buyer-ink mb-4">Categories</h2>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($categories as $category)
                            <a href="{{ route('buyer.categories.show', $category->id) }}"
                               class="rounded-xl border border-buyer-line bg-white p-4 text-center hover:shadow-md transition-shadow">
                                <i data-lucide="{{ $category->icon }}" width="32" height="32" class="mx-auto mb-3 text-primary-800"></i>
                                <p class="text-[13px] font-semibold text-buyer-ink">{{ $category->label }}</p>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Products --}}
            @if ($products->isNotEmpty())
                <div>
                    <h2 class="font-serif text-[20px] text-buyer-ink mb-4">Products</h2>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($products as $product)
                            @include('Components.buyer.product-card', [
                                'product' => $product,
                                'url' => route('buyer.products.show', $product->id),
                            ])
                        @endforeach
                    </div>

                    {{-- Pagination --}}
                    <div class="mt-12 flex justify-center">
                        {{ $products->links() }}
                    </div>
                </div>
            @endif
        @else
            <div class="rounded-2xl border-2 border-dashed border-buyer-line bg-white p-12 text-center">
                <i data-lucide="search" width="48" height="48" class="mx-auto mb-4 text-buyer-ink-soft"></i>
                <p class="font-serif text-[20px] text-buyer-ink mb-2">No results found</p>
                @if ($query === '')
                    <p class="text-[14px] text-buyer-ink-soft mb-6">Enter a search term to get started.</p>
                @elseif ($tooShort)
                    <p class="text-[14px] text-buyer-ink-soft mb-6">Enter at least 2 characters to search.</p>
                @else
                    @include('Buyer.Search._empty-hint', [
                        'term' => $query,
                        'suggestionUrl' => $suggestion ? route('buyer.search.index', ['q' => $suggestion]) : null,
                    ])
                @endif
                <a href="{{ route('buyer.home') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-buyer-ink px-6 py-3 text-[13px] font-semibold text-white hover:opacity-90 transition-opacity">
                    <i data-lucide="arrow-left" width="14" height="14"></i>
                    Back to Home
                </a>
            </div>
        @endif
    </div>
</div>
@endsection
