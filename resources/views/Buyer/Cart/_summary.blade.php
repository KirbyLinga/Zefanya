{{--
    Order Summary card (right column; sticky on lg+).

    Expects: $summary (App\Services\Buyer\CartSummary), $voucher (?App\Models\Shared\Voucher),
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
      #cartpage-voucher-chips / .js-cartpage-voucher-chip / .js-cartpage-voucher-remove
      .js-cartpage-checkout-btn    both checkout buttons
--}}
@php
    $selectedLines = $summary->selectedLineCount();
    $hasVoucher  = $summary->hasVoucher();
    $canCheckout = $summary->subtotalCentavos > 0;
    $appliedVouchers = $summary->voucherApplication->toArray();
@endphp

<aside class="lg:sticky lg:top-6 lg:h-fit" id="cartpage-summary">
  <div class="rounded-2xl border border-buyer-line bg-white p-5">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-3 mb-4 pb-4 border-b border-buyer-line/50">
      <h2 class="font-serif text-[20px] font-semibold text-buyer-ink">Order Summary</h2>
      <span id="cartpage-summary-count"
            class="rounded-full bg-secondary-100 px-3 py-1 text-[11px] font-semibold text-buyer-ink"
            aria-live="polite"
            aria-label="Selected item count">
        {{ $selectedLines }} {{ Str::plural('item', $selectedLines) }}
      </span>
    </div>

    {{-- Totals breakdown --}}
    <div class="flex flex-col gap-3" aria-live="polite">
      {{-- Subtotal --}}
      <div class="flex items-center justify-between">
        <span class="text-[13px] text-neutral-500">Subtotal</span>
        <span id="cartpage-subtotal" class="font-serif text-[15px] font-semibold text-buyer-ink">
          {{ $summary->formattedSubtotal() }}
        </span>
      </div>

      {{-- Shipping placeholder --}}
      <div class="flex items-center justify-between">
        <span class="text-[13px] text-neutral-500">Shipping</span>
        <span class="text-[13px] text-neutral-400 font-medium">Calculated at checkout</span>
      </div>

      {{-- Voucher Discount --}}
      <div id="cartpage-discount-row"
           class="flex items-center justify-between {{ $hasVoucher ? '' : 'hidden' }}">
        <span class="text-[13px] text-neutral-500">Voucher Discount</span>
        <span id="cartpage-discount" class="font-semibold text-[15px] text-tertiary-700">
          -{{ $summary->formattedDiscount() }}
        </span>
      </div>

      {{-- Divider --}}
      <div class="my-1 border-t border-buyer-line/50"></div>

      {{-- Subtotal before shipping --}}
      <div class="flex items-end justify-between">
        <span class="text-[14px] font-bold text-buyer-ink">Subtotal before shipping</span>
        <span id="cartpage-total" class="font-serif text-[26px] font-semibold text-buyer-ink"
              aria-live="polite">{{ $summary->formattedTotal() }}</span>
      </div>

      {{-- Note about final amount --}}
      <p class="text-[11px] text-neutral-400 italic">Final payable amount will be shown at checkout</p>
    </div>

    {{-- Voucher section --}}
    <div class="mt-5 pt-5 border-t border-buyer-line/50">
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

      {{-- Applied vouchers (one platform, one shipping, one per shop) --}}
      <div id="cartpage-voucher-chips"
           class="mt-2 flex flex-col gap-2 {{ $hasVoucher ? '' : 'hidden' }}">
        @foreach ($appliedVouchers as $applied)
          <div class="js-cartpage-voucher-chip flex items-center gap-2 rounded-lg border border-tertiary-200 bg-tertiary-50 px-2.5 py-2"
               data-slot="{{ $applied['slot'] }}">
            <i data-lucide="tag" width="13" height="13" class="shrink-0 text-tertiary-700"></i>
            <span class="js-cartpage-voucher-chip-label min-w-0 flex-1 truncate text-[11.5px] font-semibold text-tertiary-700">
              {{ $applied['scope'] === 'shipping' ? $applied['code'].' (shipping)' : $applied['label'] }}
            </span>
            <button
              type="button"
              class="js-cartpage-voucher-remove shrink-0 text-tertiary-700 transition-opacity hover:opacity-70"
              data-slot="{{ $applied['slot'] }}"
              aria-label="Remove {{ $applied['code'] }}"
            >
              <i data-lucide="x" width="14" height="14"></i>
            </button>
          </div>
        @endforeach
      </div>

      {{-- Shown when a previously applied voucher was dropped on recalculation --}}
      @if ($voucherNotice)
        <p class="mt-1.5 text-[11.5px] font-medium text-primary-700">{{ $voucherNotice }}</p>
      @endif
    </div>

    {{-- Checkout button --}}
    <div class="mt-5 pt-5 border-t border-buyer-line/50">
      <button
        type="button"
        class="js-cartpage-checkout-btn flex w-full items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-40"
        @disabled(! $canCheckout)
      >
        Proceed to Checkout
        <i data-lucide="arrow-right" width="15" height="15"></i>
      </button>
    </div>
  </div>
</aside>