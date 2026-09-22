{{--
    PDP Buy-box partial.
    Expects: $product, $price, $oldPrice, $discountPct, $rating, $reviewCount,
             $soldCount, $colors, $sizes
--}}
<div class="flex flex-col gap-5 rounded-3xl border border-buyer-line bg-white p-6 lg:self-start lg:sticky lg:top-6">

  {{-- Category label --}}
  <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-primary-700">
    {{ $product->category?->label ?? 'Apparel' }}
  </p>

  {{-- Title --}}
  <h1 class="font-serif text-[24px] leading-[1.2] font-semibold text-buyer-ink sm:text-[28px]">
    {{ $product->name }}
  </h1>

  {{-- Rating + sold --}}
  <div class="flex flex-wrap items-center gap-3 text-[12.5px]">
    <div class="flex items-center gap-1">
      @for ($s = 1; $s <= 5; $s++)
        @if ($s <= floor($rating))
          <i class="fa-solid fa-star text-[11px] text-buyer-star"></i>
        @elseif ($s == ceil($rating) && $rating != floor($rating))
          <i class="fa-solid fa-star-half-stroke text-[11px] text-buyer-star"></i>
        @else
          <i class="fa-regular fa-star text-[11px] text-neutral-300"></i>
        @endif
      @endfor
      <span class="ml-1 font-semibold text-buyer-ink">{{ number_format($rating, 1) }}</span>
    </div>
    <a href="#pdp-reviews" class="text-neutral-400 underline-offset-2 hover:underline">
      {{ number_format($reviewCount) }} Reviews
    </a>
    <span class="text-neutral-300">·</span>
    <span class="text-neutral-400">{{ number_format($soldCount) }} Sold</span>
  </div>

  {{-- Price block --}}
  <div class="rounded-2xl bg-secondary-50 px-4 py-3">
    <div class="flex flex-wrap items-baseline gap-3">
      <span class="font-serif text-[28px] font-semibold text-buyer-ink">{{ $price }}</span>
      @if ($oldPrice)
        <span class="text-[14px] text-neutral-400 line-through">{{ $oldPrice }}</span>
        <span class="rounded-full bg-primary-100 px-2.5 py-0.5 text-[11px] font-bold text-primary-800">
          {{ $discountPct }} OFF
        </span>
      @endif
    </div>
    <p class="mt-1.5 text-[11.5px] text-neutral-400">
      Free shipping · 3× installment at <span class="font-semibold text-buyer-ink">{{ $price }}/mo</span>
    </p>
  </div>

  {{-- Color swatches --}}
  <div>
    <div class="mb-2.5 flex items-center justify-between">
      <span class="text-[11.5px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Color</span>
      <span id="pdp-selected-color" class="text-[12px] font-medium text-buyer-ink">
        {{ $colors[0]['name'] ?? '' }}
      </span>
    </div>
    <div class="flex flex-wrap gap-2.5" role="group" aria-label="Select colour">
      @foreach ($colors as $i => $color)
        <button
          type="button"
          class="js-pdp-color h-8 w-8 rounded-full border-2 transition-all duration-150
            {{ $i === 0 ? 'border-buyer-ink ring-2 ring-buyer-ink ring-offset-2' : 'border-transparent hover:border-primary-400' }}
            {{ $color['token'] }}"
          data-color="{{ $color['name'] }}"
          data-index="{{ $i }}"
          aria-label="{{ $color['name'] }}"
          aria-pressed="{{ $i === 0 ? 'true' : 'false' }}"
        ></button>
      @endforeach
    </div>
  </div>

  {{-- Size selector --}}
  <div>
    <div class="mb-2.5 flex items-center justify-between">
      <span class="text-[11.5px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Size</span>
      <a href="#" class="text-[11.5px] font-semibold text-primary-700 hover:underline">Size Guide</a>
    </div>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Select size">
      @foreach ($sizes as $size)
        <button
          type="button"
          class="js-pdp-size min-w-[44px] rounded-lg border border-buyer-line bg-white px-3 py-2 text-[12.5px] font-semibold text-buyer-ink transition-all duration-150
            hover:border-buyer-ink
            [&.selected]:border-buyer-ink [&.selected]:bg-buyer-ink [&.selected]:text-white"
          data-size="{{ $size }}"
          aria-pressed="false"
        >{{ $size }}</button>
      @endforeach
    </div>
    {{-- Low-stock warning --}}
    @if ($product->isLowStock())
      <p class="mt-2 flex items-center gap-1 text-[11.5px] font-semibold text-red-500">
        <i data-lucide="alert-triangle" width="13" height="13"></i>
        Only {{ $product->stock_quantity }} left in stock
      </p>
    @endif
    {{-- Inline size-required error (hidden by default, shown by JS) --}}
    <p id="pdp-size-error" class="mt-2 hidden text-[11.5px] font-semibold text-red-500">
      Please select a size before adding to cart.
    </p>
  </div>

  {{-- Quantity + CTA --}}
  <div class="flex flex-col gap-3">
    {{-- Qty stepper --}}
    <div class="flex items-center gap-3">
      <span class="text-[11.5px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Qty</span>
      <div class="inline-flex items-center rounded-full border border-buyer-line">
        <button type="button" id="pdp-qty-down"
                class="flex h-8 w-8 items-center justify-center rounded-full text-buyer-ink-soft transition-colors hover:bg-secondary-100"
                aria-label="Decrease quantity">
          <i data-lucide="minus" width="13" height="13"></i>
        </button>
        <span id="pdp-qty" class="w-8 text-center text-[13px] font-semibold text-buyer-ink">1</span>
        <button type="button" id="pdp-qty-up"
                class="flex h-8 w-8 items-center justify-center rounded-full text-buyer-ink-soft transition-colors hover:bg-secondary-100"
                aria-label="Increase quantity">
          <i data-lucide="plus" width="13" height="13"></i>
        </button>
      </div>
    </div>

    {{-- Add to Cart --}}
    <button
      type="button"
      id="pdp-add-cart"
      class="flex w-full items-center justify-center gap-2 rounded-full bg-buyer-ink py-3.5 text-[13px] font-semibold text-white shadow-[0_8px_20px_-8px_rgba(58,37,41,0.5)] transition-opacity hover:opacity-90"
      data-product-id="{{ $product->id }}"
      data-stock="{{ $product->stock_quantity }}"
    >
      <i data-lucide="shopping-bag" width="15" height="15"></i>
      Add to Atelier Bag
    </button>

    {{-- Express Buy Now --}}
    <button
      type="button"
      id="pdp-buy-now"
      class="flex w-full items-center justify-center gap-2 rounded-full border border-buyer-ink bg-transparent py-3.5 text-[13px] font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white"
      data-product-id="{{ $product->id }}"
    >
      Express Buy Now with 1-Click
    </button>
  </div>

  {{-- Trust badges --}}
  <div class="grid grid-cols-3 gap-3 border-t border-buyer-line pt-4">
    <div class="flex flex-col items-center gap-1.5 text-center">
      <i data-lucide="truck" width="20" height="20" class="text-tertiary-500"></i>
      <span class="text-[10.5px] leading-tight text-neutral-500">Complimentary Shipping</span>
    </div>
    <div class="flex flex-col items-center gap-1.5 text-center">
      <i data-lucide="shield-check" width="20" height="20" class="text-tertiary-500"></i>
      <span class="text-[10.5px] leading-tight text-neutral-500">Secure Checkout</span>
    </div>
    <div class="flex flex-col items-center gap-1.5 text-center">
      <i data-lucide="refresh-cw" width="20" height="20" class="text-tertiary-500"></i>
      <span class="text-[10.5px] leading-tight text-neutral-500">Easy Returns</span>
    </div>
  </div>

</div>
