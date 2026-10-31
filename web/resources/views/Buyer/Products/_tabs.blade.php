{{-- Specifications and description, with a jump bar that also reaches the reviews below. --}}
@php
    $specSubCategories = $product->storeCategories->filter->isPubliclyVisible();
    $specLinkClass = 'font-semibold text-buyer-ink underline decoration-buyer-line underline-offset-4 hover:text-primary-800';
    $specTabClass = 'border-b-2 border-transparent py-3 text-[13px] font-semibold text-buyer-ink-soft no-underline transition-colors hover:text-buyer-ink aria-[current=true]:border-primary-800 aria-[current=true]:text-buyer-ink';
    $specRowClass = 'grid grid-cols-[120px_minmax(0,1fr)] gap-4 border-b border-buyer-line py-2.5 text-[14px] last:border-b-0';
@endphp
<section class="mt-6 overflow-hidden rounded-2xl border border-buyer-line bg-white">
  <nav data-pdp-tabs aria-label="Product details" class="flex gap-6 overflow-x-auto border-b border-buyer-line px-5">
    <a href="#specifications" aria-current="true" class="{{ $specTabClass }}">Specifications</a>
    @if (trim((string) $description) !== '')
      <a href="#description" aria-current="false" class="{{ $specTabClass }}">Description</a>
    @endif
    <a href="#reviews" aria-current="false" class="{{ $specTabClass }}">Reviews <span class="ml-1 text-[12px] font-normal text-buyer-ink-soft">{{ number_format($product->reviews_count) }}</span></a>
  </nav>

  <div id="specifications" class="scroll-mt-24 px-5 py-4">
    <h2 class="mb-2 font-serif text-[15px] font-semibold text-buyer-ink">Specifications</h2>
    <dl>
      @if ($product->category)
        <div class="{{ $specRowClass }}">
          <dt class="text-buyer-ink-soft">Category</dt>
          <dd><a href="{{ route('buyer.categories.show', $product->category_id) }}" class="{{ $specLinkClass }}">{{ $product->category->label }}</a></dd>
        </div>
      @endif
      @if ($specSubCategories->isNotEmpty() && $product->seller)
        <div class="{{ $specRowClass }}">
          <dt class="text-buyer-ink-soft">Sub-category</dt>
          <dd class="flex flex-wrap gap-x-3 gap-y-1">
            @foreach ($specSubCategories as $subCategory)
              <a href="{{ route('buyer.stores.categories.show', ['seller' => $product->seller, 'storeCategory' => $subCategory]) }}" class="{{ $specLinkClass }}">{{ $subCategory->name }}</a>
            @endforeach
          </dd>
        </div>
      @endif
      @if ($product->seller)
        <div class="{{ $specRowClass }}">
          <dt class="text-buyer-ink-soft">Store</dt>
          <dd><a href="{{ route('buyer.stores.show', $product->seller) }}" class="{{ $specLinkClass }}">{{ $sellerData['name'] }}</a></dd>
        </div>
      @endif
      @if (filled($product->brand) && $product->brand !== 'No brand')
        <div class="{{ $specRowClass }}">
          <dt class="text-buyer-ink-soft">Brand</dt>
          <dd class="text-buyer-ink">{{ $product->brand }}</dd>
        </div>
      @endif
      @if (filled($product->condition))
        <div class="{{ $specRowClass }}">
          <dt class="text-buyer-ink-soft">Condition</dt>
          <dd class="text-buyer-ink">{{ $product->condition }}</dd>
        </div>
      @endif
      @foreach ($product->options as $option)
        <div class="{{ $specRowClass }}">
          <dt class="text-buyer-ink-soft">{{ $option->name }}</dt>
          <dd class="text-buyer-ink">{{ $option->values->pluck('value')->implode(', ') }}</dd>
        </div>
      @endforeach
    </dl>
  </div>

  @if (trim((string) $description) !== '')
    <div id="description" class="scroll-mt-24 border-t border-buyer-line px-5 py-4">
      <h2 class="mb-2 font-serif text-[15px] font-semibold text-buyer-ink">Description</h2>
      <p class="whitespace-pre-line text-[14px] leading-relaxed text-buyer-ink-soft">{{ $description }}</p>
    </div>
  @endif
</section>
