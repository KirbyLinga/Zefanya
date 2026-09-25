{{--
    Cart seller-group partial.

    Expects: $seller (Seller model), $items (Collection of CartItem)

    DOM contract for cart.js:
      .js-cartpage-seller-group  [data-seller-id]
      .js-cartpage-seller-check  [data-seller-id]
--}}
<div class="js-cartpage-seller-group rounded-2xl border border-buyer-line bg-white"
     data-seller-id="{{ $seller->id }}">

  {{-- Seller header --}}
  <div class="flex items-center gap-3 border-b border-buyer-line px-5 py-3.5">
    <input
      type="checkbox"
      class="js-cartpage-seller-check h-4 w-4 cursor-pointer rounded accent-primary-800"
      aria-label="Select every item from {{ $seller->business_name }}"
      data-seller-id="{{ $seller->id }}"
      checked
    >
    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-secondary-100 text-buyer-ink"
          aria-hidden="true">
      <i data-lucide="store" width="14" height="14"></i>
    </span>
    <span class="truncate text-[13.5px] font-semibold text-buyer-ink">{{ $seller->business_name }}</span>
  </div>

  {{-- Item rows --}}
  @foreach ($items as $item)
    @include('Buyer.Cart._item', ['item' => $item, 'unavailable' => false])
  @endforeach

</div>
