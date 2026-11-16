@php($categoryRecommendations = $recommendations ?? collect())
<h2 tabindex="-1" data-visual-results-heading class="mb-2 font-serif text-xl text-[var(--text-ink)]">{{ $products->isNotEmpty() ? (($recognizedCategory ?? null) ? 'Visually similar products' : 'Similar image structure, colors and layout') : ($categoryRecommendations->isNotEmpty() ? 'Category recommendations' : (($recognitionStatus ?? null) === 'uncertain' ? 'No confident recognition' : 'No similar products found')) }}</h2>
@if ($partial ?? false)<p class="mb-4 text-xs text-[var(--text-muted)]">Some new or changed catalog photos are not indexed yet.</p>@endif
@if (($recognizedCategory ?? null))
    <p class="mb-3 text-sm text-[var(--text-muted)]">Recognized product type: <strong>{{ $recognizedCategory }}</strong>. Category recommendations may differ in design, brand or model.</p>
@elseif (app(\App\Services\Buyer\VisualSemanticRecognition::class)->enabled())
    <p class="mb-3 text-sm text-[var(--text-muted)]">Object recognition was unavailable or uncertain. Photo comparison remains available.</p>
@endif
@if ($products->isEmpty() && $categoryRecommendations->isEmpty())
    @if ($recognizedCategory ?? null)
        <p class="text-sm leading-relaxed text-[var(--text-muted)]">No qualifying products of this type were found in the available catalog and selected filters. Try another category or browse the catalog.</p>
    @else
    <p class="text-sm leading-relaxed text-[var(--text-muted)]">No catalog photos were similar enough. Try a clearer photo cropped around one item, choose a product category, or browse the catalog. Only currently available products are included.</p>
    <p class="mt-2 text-sm leading-relaxed text-[var(--text-muted)]">This search compares photographs. An illustration, a group of items or a different viewpoint may not match a catalog photo, even when that type of product is available.</p>
    @endif
    @if (($browseCategories ?? collect())->isNotEmpty())
        <nav aria-label="Browse categories after image search" class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-raised)] p-4">
            <h3 class="font-semibold text-[var(--text-ink)]">Browse available products by category</h3>
            <p class="mt-1 text-sm text-[var(--text-muted)]">These are browsing options, not matches identified from your image.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($browseCategories as $category)
                    <a href="{{ route('buyer.categories.show', $category->id) }}" class="inline-flex min-h-11 max-w-full items-center rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-sm font-semibold text-[var(--link)] underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">{{ $category->label }}</a>
                @endforeach
            </div>
        </nav>
    @endif
    <a href="{{ route('buyer.products.index') }}" class="mt-3 inline-flex min-h-10 items-center font-semibold text-[var(--link)] underline">Browse products</a>
@elseif ($products->isNotEmpty())
    <p class="mb-4 text-xs text-[var(--text-muted)]">{{ $products->count() }} results. These are visual suggestions, not confirmed identical items.</p>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
        @foreach ($products as $product)
            @include('Components.buyer.product-card', ['product' => $product, 'url' => route('buyer.products.show', $product->id)])
        @endforeach
    </div>
@endif

@if ($categoryRecommendations->isNotEmpty())
    <section class="mt-6" aria-label="Products in the recognized category">
        <h3 class="mb-2 font-serif text-xl text-[var(--text-ink)]">More products in {{ $recognizedCategory }}</h3>
        <p class="mb-4 text-sm text-[var(--text-muted)]">These products match the recognized type. They are category recommendations, not confirmed visual or exact matches.</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
            @foreach ($categoryRecommendations as $product)
                @include('Components.buyer.product-card', ['product' => $product, 'url' => route('buyer.products.show', $product->id)])
            @endforeach
        </div>
    </section>
@endif
