{{--
    Order Summary card (right column; sticky on lg+).

    Expects: $summary (App\Services\CartSummary), $voucher (?App\Models\Voucher),
             $voucherNotice (?string)

    DOM contract for cart.js:
      #cartpage-summary            wrapper (also rendered in the mobile bar)
      #cartpage-summary-count      "N items" pill
      #cartpage-subtotal           subtotal value
      #cartpage-discount-row       the Voucher Discount row (hidden when no voucher)
      #cartpage-discount           discount value
      #cartpage-total              total value
      #cartpage-mobile-total       total shown in the mobile bottom bar
      #cartpage-voucher-input / #cartpage-voucher-apply / #cartpage-voucher-error
      #cartpage-voucher-chip / #cartpage-voucher-chip-label / #cartpage-voucher-remove
      .js-cartpage-checkout-btn    both checkout buttons
--}}
@php
    $selectedQty = $summary->selectedQuantity();
    $hasVoucher  = $voucher !== null;
    $canCheckout = $summary->subtotalCentavos > 0;
@endphp

<aside class="lg:sticky lg:top-6 lg:h-fit" id="cartpage-summary">
  <div class="rounded-2xl border border-buyer-line bg-white p-5">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-3">
      <h2 class="font-serif text-[20px] font-semibold text-buyer-ink">Order Summary</h2>
      <span id="cartpage-summary-count"
            class="rounded-full bg-secondary-100 px-3 py-1 text-[11px] font-semibold text-buyer-ink"
            aria-live="polite"
            aria-label="Selected item count">
        {{ $selectedQty }} {{ Str::plural('item', $selectedQty) }}
      </span>
    </div>

    {{-- Totals --}}
    <div class="mt-4 flex flex-col gap-2.5" aria-live="polite">
      <div class="flex items-center justify-between text-[12.5px]">
        <span class="text-neutral-400">Subtotal</span>
        <span id="cartpage-subtotal" class="font-serif text-[14px] font-semibold text-buyer-ink">
          {{ $summary->formattedSubtotal() }}
        </span>
      </div>

      <div class="flex items-center justify-between text-[12.5px]">
        <span class="text-neutral-400">Shipping</span>
        <span class="text-neutral-400">Calculated at checkout</span>
      </div>

      <div id="cartpage-discount-row"
           class="flex items-center justify-between text-[12.5px] {{ $hasVoucher ? '' : 'hidden' }}">
        <span class="text-neutral-400">Voucher Discount</span>
        <span id="cartpage-discount" class="font-semibold text-tertiary-700">
          {{ $summary->formattedDiscount() }}
        </span>
      </div>
    </div>

    {{-- Voucher --}}
    <div class="mt-4">
      <div class="flex items-center gap-1 rounded-xl border border-buyer-line bg-blush p-1">
        <label for="cartpage-voucher-input" class="sr-only">Voucher code</label>
        <input
          type="text"
          id="cartpage-voucher-input"
          name="voucher_code"
          placeholder="Enter voucher code"
          autocomplete="off"
          spellcheck="false"
          class="min-w-0 flex-1 border-0 bg-transparent px-2.5 py-2 font-sans text-[12.5px] text-buyer-ink outline-none placeholder:text-neutral-400"
        >
        <button
          type="button"
          id="cartpage-voucher-apply"
          class="shrink-0 rounded-lg bg-primary-800 px-4 py-2 text-[11.5px] font-semibold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50"
        >
          Apply
        </button>
      </div>

      {{-- Inline error --}}
      <p id="cartpage-voucher-error" class="mt-1.5 hidden text-[11.5px] font-medium text-primary-700" role="alert"></p>

      {{-- Applied voucher chip --}}
      <div id="cartpage-voucher-chip"
           class="mt-2 flex items-center gap-2 rounded-lg border border-tertiary-200 bg-tertiary-50 px-2.5 py-2 {{ $hasVoucher ? '' : 'hidden' }}">
        <i data-lucide="tag" width="13" height="13" class="shrink-0 text-tertiary-700"></i>
        <span id="cartpage-voucher-chip-label" class="min-w-0 flex-1 truncate text-[11.5px] font-semibold text-tertiary-700">
          {{ $hasVoucher ? $summary->voucherChipLabel() : '' }}
        </span>
        <button
          type="button"
          id="cartpage-voucher-remove"
          class="shrink-0 text-tertiary-700 transition-opacity hover:opacity-70"
          aria-label="Remove the applied voucher"
        >
          <i data-lucide="x" width="14" height="14"></i>
        </button>
      </div>

      {{-- Shown when a previously applied voucher was dropped on recalculation --}}
      @if ($voucherNotice)
        <p class="mt-1.5 text-[11.5px] font-medium text-primary-700">{{ $voucherNotice }}</p>
      @endif
    </div>

    {{-- Total --}}
    <div class="mt-4 border-t border-buyer-line pt-4">
      <div class="flex items-end justify-between">
        <span class="text-[13px] font-semibold text-buyer-ink">Total</span>
        <span id="cartpage-total" class="font-serif text-[26px] font-semibold text-buyer-ink"
              aria-live="polite">{{ $summary->formattedTotal() }}</span>
      </div>

      <button
        type="button"
        class="js-cartpage-checkout-btn mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-40"
        @disabled(! $canCheckout)
      >
        Proceed to Checkout
        <i data-lucide="arrow-right" width="15" height="15"></i>
      </button>

      <p class="mt-3 flex items-center justify-center gap-1.5 text-[11px] text-neutral-400">
        <i data-lucide="lock" width="12" height="12"></i>
        Secure 256-bit SSL encrypted checkout
      </p>
    </div>
  </div>
</aside>