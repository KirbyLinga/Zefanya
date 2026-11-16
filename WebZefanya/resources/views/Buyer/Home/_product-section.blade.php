{{-- A titled row of product cards; hidden when there is nothing real to show.
     Expects $sectionId, $title, $products and optionally $viewAllUrl. --}}
@if ($products->isNotEmpty())
  <section id="{{ $sectionId }}" class="py-6 sm:py-10">
    <div class="mb-5 flex items-center justify-between">
      <h2 class="font-serif text-2xl text-buyer-ink sm:text-[28px]">{{ $title }}</h2>
      @isset ($viewAllUrl)
        <a href="{{ $viewAllUrl }}"
           class="inline-flex items-center gap-1.5 text-[12px] font-semibold uppercase tracking-[1.6px] text-primary-800 transition-colors hover:text-primary-900">
          View All
          <i data-lucide="arrow-right" width="12" height="12"></i>
        </a>
      @endisset
    </div>
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      @foreach ($products as $product)
        @include('Components.buyer.product-card', [
            'product' => $product,
            'url'     => route('buyer.products.show', $product->id),
        ])
      @endforeach
    </div>
  </section>
@endif
