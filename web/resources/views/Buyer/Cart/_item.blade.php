{{--
    Cart item row partial.
    Expects: $item (CartItem with product.images/seller/category loaded)
             $unavailable (bool) — dims the row and disables its checkbox
--}}
@php
    $product  = $item->product;
    $imageUrl = $product?->catalogImageUrl();
    $pdpUrl   = $product ? route('buyer.products.show', $product->id) : null;
    $stock    = (int) ($product?->stock_quantity ?? 0);
    $catLabel = $product?->category?->label;
    $unit     = (int) bcmul((string) ($product?->price ?? 0), '100', 0);
@endphp

<div
  class="js-cartpage-item-row flex items-start gap-4 border-b border-buyer-line/60 px-5 py-4 transition-opacity duration-200 last:border-b-0 {{ $unavailable ? 'opacity-50' : '' }}"
  data-item-id="{{ $item->id }}"
  data-seller-id="{{ $product?->seller_id }}"
  data-stock="{{ $stock }}"
  data-unit-centavos="{{ $unit }}"
  data-line-total-centavos="{{ $unavailable ? 0 : $item->lineTotalCentavos() }}"
  data-available="{{ $unavailable ? '0' : '1' }}"
>
  {{-- Select checkbox --}}
  <div class="mt-1 shrink-0">
    <input
      type="checkbox"
      class="js-cartpage-item-check h-4 w-4 cursor-pointer rounded accent-primary-800"
      data-item-id="{{ $item->id }}"
      data-seller-id="{{ $product?->seller_id }}"
      aria-label="Select {{ $product?->name ?? 'this item' }}"
      @checked(! $unavailable)
      @disabled($unavailable)
    >
  </div>

  {{-- Thumbnail with the category chip pinned to its bottom-right corner --}}
  <div class="relative h-[88px] w-[70px] shrink-0 overflow-hidden rounded-xl bg-secondary-100">
    @if ($imageUrl)
      <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
    @else
      <div class="flex h-full w-full items-center justify-center text-primary-300">
        <i data-lucide="package" width="26" height="26"></i>
      </div>
    @endif
    @if ($catLabel)
      <span class="absolute right-1 bottom-1 max-w-[calc(100%-0.5rem)] truncate rounded bg-buyer-ink/75 px-1.5 py-0.5 text-[8px] font-bold tracking-[0.05em] text-white uppercase">
        {{ $catLabel }}
      </span>
    @endif
  </div>

  {{-- Product info --}}
  <div class="min-w-0 flex-1">
    @if ($pdpUrl)
      <a href="{{ $pdpUrl }}"
         class="line-clamp-2 text-[13.5px] font-semibold leading-snug text-buyer-ink hover:underline hover:underline-offset-2">
        {{ $product->name }}
      </a>
    @else
      <span class="line-clamp-2 text-[13.5px] font-semibold leading-snug text-buyer-ink">
        {{ $product?->name ?? 'Product no longer available' }}
      </span>
    @endif

    {{-- TODO: colour / size belong here once a product_variants table exists.
         There are no variants yet, so only the multi-quantity hint is shown. --}}
    <p class="mt-0.5 text-[11.5px] text-neutral-400">
      @if ($unavailable)
        No longer available
      @elseif ($item->quantity > 1)
        Set of {{ $item->quantity }} ({{ $product->formattedPrice() }} each)
      @endif
    </p>

    @if (! $unavailable && $product?->isLowStock())
      <p class="mt-1 flex items-center gap-1 text-[11.5px] font-semibold text-primary-700">
        <i data-lucide="alert-triangle" width="11" height="11"></i>
        Only {{ $stock }} left
      </p>
    @endif
  </div>

  {{-- Right column: line total, quantity stepper, remove --}}
  <div class="flex shrink-0 flex-col items-end gap-2">
    <span class="js-cartpage-line-total font-serif text-[15px] font-semibold text-buyer-ink">
      {{ $unavailable ? '—' : $item->formattedLineTotal() }}
    </span>

    @unless ($unavailable)
      <div class="inline-flex items-center rounded-full border border-buyer-line bg-buyer-cream">
        <button
          type="button"
          class="js-cartpage-qty-down flex h-7 w-7 items-center justify-center rounded-full text-buyer-ink-soft transition-colors hover:bg-secondary-100 disabled:opacity-30"
          data-item-id="{{ $item->id }}"
          data-stock="{{ $stock }}"
          aria-label="Decrease quantity of {{ $product->name }}"
          @disabled($item->quantity <= 1)
        >
          <i data-lucide="minus" width="11" height="11"></i>
        </button>
        <span class="js-cartpage-qty-display w-8 text-center text-[13px] font-semibold text-buyer-ink"
              aria-live="polite"
              aria-label="Quantity of {{ $product->name }}">{{ $item->quantity }}</span>
        <button
          type="button"
          class="js-cartpage-qty-up flex h-7 w-7 items-center justify-center rounded-full text-buyer-ink-soft transition-colors hover:bg-secondary-100 disabled:opacity-30"
          data-item-id="{{ $item->id }}"
          data-stock="{{ $stock }}"
          aria-label="Increase quantity of {{ $product->name }}"
          @disabled($item->quantity >= $stock)
        >
          <i data-lucide="plus" width="11" height="11"></i>
        </button>
      </div>
    @endunless

    <button
      type="button"
      class="js-cartpage-remove flex items-center gap-1 text-[11.5px] text-neutral-400 transition-colors hover:text-primary-700"
      data-item-id="{{ $item->id }}"
      aria-label="Remove {{ $product?->name ?? 'this item' }} from cart"
    >
      <i data-lucide="trash-2" width="12" height="12"></i>
      Remove
    </button>
  </div>
</div>
