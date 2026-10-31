{{--
    PDP Buy-box partial.
    Expects: $product, $price, $variantAxes (null|array), $variantData (null|array)
--}}
@use('App\Services\Buyer\BuyerPurchaseLimit')
@php
    $purchasedQuantity = (int) ($purchasedQuantity ?? 0);
    $cartQuantity = (int) ($cartQuantity ?? 0);
    $quantityMax = max(0, min($product->buyerStockQuantity(), BuyerPurchaseLimit::MAX - $purchasedQuantity - $cartQuantity));
@endphp
<div class="flex flex-col gap-5 rounded-3xl border border-buyer-line bg-white p-6">

  {{-- Category label --}}
  <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-primary-700">
    {{ $product->category?->label ?? 'Apparel' }}
  </p>

  {{-- Title --}}
  <h1 class="font-serif text-[24px] leading-[1.2] font-semibold text-buyer-ink sm:text-[28px]">
      {{ $product->name }}
  </h1>

  @include('Components.buyer.wishlist-heart', ['heartPosition' => 'self-end'])
  @if($product->isOnVacation())<p role="status" class="rounded-lg bg-primary-50 p-3 font-semibold text-primary-800">On vacation — purchasing is temporarily unavailable.</p>@endif

  @if($product->buyerIsOutOfStock())<p class="font-semibold text-red-600">Sold Out</p>@endif

  {{-- Price block --}}
  <div class="rounded-2xl bg-secondary-50 px-4 py-3">
    <span id="pdp-price" class="font-serif text-[28px] font-semibold text-buyer-ink">{{ $price }}</span>
    <span id="pdp-price-regular" class="ml-2 text-sm text-price-muted line-through" @if (! $pricing?->formattedRegular()) hidden @endif>{{ $pricing?->formattedRegular() }}</span>
    <span id="pdp-price-badge" class="ml-2 rounded bg-primary-800 px-2 py-1 text-xs font-semibold text-white" @if (! $pricing?->badge()) hidden @endif>{{ $pricing?->badge() }}</span>
  </div>

  {{-- REAL variant pickers — one button group per option axis --}}
  @if ($variantAxes !== null)
    @foreach ($variantAxes as $axisIndex => $axis)
      <div>
        <div class="mb-2.5 flex items-center justify-between">
          <span class="text-[11.5px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">{{ $axis['name'] }}</span>
          <span class="text-[12px] font-medium text-buyer-ink" data-pdp-axis-label="{{ $axisIndex }}"></span>
        </div>
        <div class="flex flex-wrap gap-2" role="group" aria-label="Select {{ $axis['name'] }}">
          @foreach ($axis['values'] as $value)
            <button
              type="button"
              class="js-pdp-variant-value min-w-[44px] rounded-lg border border-buyer-line bg-white px-3 py-2 text-[12.5px] font-semibold text-buyer-ink transition-all duration-150
                hover:border-buyer-ink
                [&.selected]:border-buyer-ink [&.selected]:bg-buyer-ink [&.selected]:text-white
                [&.soldout]:opacity-40 [&.soldout]:line-through"
              data-axis-index="{{ $axisIndex }}"
              data-value="{{ $value }}"
              aria-pressed="false"
            >{{ $value }}</button>
          @endforeach
        </div>
      </div>
    @endforeach

    <input type="hidden" id="pdp-variant-id" value="">
    <script type="application/json" data-pdp-variants data-selection-message="Please select a variation">@json($variantData)</script>
  @endif

  {{-- Shared error line: variant validation + Buy Now failures --}}
  <p id="pdp-variant-error" class="mt-2 hidden text-[11.5px] font-semibold text-red-500" @if ($variantAxes !== null) data-selection-message="Please select a variation" @endif data-limit-message="{{ BuyerPurchaseLimit::MESSAGE }}"></p>

  {{-- Low-stock warning (single-SKU products) --}}
  @if ($variantAxes === null && $product->buyerIsLowStock())
    <p class="flex items-center gap-1 text-[11.5px] font-semibold text-red-500">
      <i data-lucide="alert-triangle" width="13" height="13"></i>
      Only {{ $product->buyerStockQuantity() }} left in stock
    </p>
  @endif

  {{-- Stock availability status --}}
  <div class="rounded-2xl border border-buyer-line px-4 py-3">
    @if ($variantAxes !== null)
      <p id="pdp-availability" role="status" aria-live="polite" class="text-[13px] font-semibold text-buyer-ink">{{ $product->buyerIsOutOfStock() ? 'Out of Stock' : 'Please select a variation' }}</p>
    @elseif ($product->buyerIsOutOfStock())
      <div class="flex items-center gap-2">
        <i data-lucide="x-circle" width="16" height="16" class="text-red-600"></i>
        <span class="text-[13px] font-semibold text-red-600">Out of Stock</span>
      </div>
    @else
      <div class="flex items-center gap-2">
        <i data-lucide="{{ $product->buyerIsLowStock() ? 'alert-circle' : 'check-circle' }}" width="16" height="16" class="{{ $product->buyerIsLowStock() ? 'text-orange-500' : 'text-green-600' }}"></i>
        <span class="text-[13px] font-semibold {{ $product->buyerIsLowStock() ? 'text-orange-500' : 'text-green-600' }}">
          {{ $product->buyerStockQuantity() }} unit(s) available
        </span>
      </div>
    @endif
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
        <input id="pdp-qty" type="number" inputmode="numeric" min="1" max="{{ $quantityMax }}" step="1" value="1" aria-label="Quantity" data-purchase-max="{{ BuyerPurchaseLimit::MAX }}" data-purchased="{{ $purchasedQuantity }}" data-cart-total="{{ $cartQuantity }}" data-cart-quantity="{{ $cartQuantity }}" data-limit-message="{{ BuyerPurchaseLimit::MESSAGE }}" @disabled($variantAxes !== null || $quantityMax < 1 || $product->buyerIsOutOfStock() || $product->isOnVacation()) class="w-12 border-0 bg-transparent text-center text-[13px] font-semibold text-buyer-ink">
        <button type="button" id="pdp-qty-up"
                class="flex h-8 w-8 items-center justify-center rounded-full text-buyer-ink-soft transition-colors hover:bg-secondary-100"
                aria-label="Increase quantity">
          <i data-lucide="plus" width="13" height="13"></i>
        </button>
      </div>
    </div>

    {{-- Checkout = Buy Now (this item only) --}}
    <button
      type="button"
      id="pdp-buy-now"
      data-vacation="{{ $product->isOnVacation() ? '1' : '0' }}"
      class="flex w-full items-center justify-center gap-2 rounded-full bg-buyer-ink py-3.5 text-[13px] font-semibold text-white shadow-[0_8px_20px_-8px_rgba(58,37,41,0.5)] transition-opacity hover:opacity-90"
      data-product-id="{{ $product->id }}"
      @disabled(($variantAxes === null && ($product->buyerIsOutOfStock() || $quantityMax < 1)) || $product->isOnVacation())
    >
      <i data-lucide="shopping-bag" width="15" height="15"></i>
      Checkout
    </button>

    {{-- Add to Cart --}}
    <button
      type="button"
      id="pdp-add-cart"
      data-vacation="{{ $product->isOnVacation() ? '1' : '0' }}"
      class="flex w-full items-center justify-center gap-2 rounded-full border border-buyer-ink bg-transparent py-3.5 text-[13px] font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white"
      data-product-id="{{ $product->id }}"
      data-stock="{{ $variantAxes === null ? $product->buyerStockQuantity() : 0 }}"
      @disabled(($variantAxes === null && ($product->buyerIsOutOfStock() || $quantityMax < 1)) || $product->isOnVacation())
    >
      Add to Cart
    </button>

   </div>

</div>
