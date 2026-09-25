@extends('Buyer.Layouts.app')

@section('title', 'Your Cart')

@section('content')
@php
    $isCartEmpty = $summary->isEmpty();
    $availableLines = $summary->availableLineCount();
@endphp
<div class="min-h-screen bg-blush py-8">
  <div class="mx-auto max-w-[1360px] px-4 sm:px-8">

    {{-- Page heading --}}
    <div class="mb-6">
      <h1 class="font-serif text-[32px] font-semibold text-buyer-ink sm:text-[38px]">Your Shopping Cart</h1>
      <p class="mt-1 text-[13px] text-neutral-400">Review your items before checkout.</p>
    </div>

    {{-- ── Populated cart (not rendered at all when the cart is empty, so the
         empty page contains no toolbar/summary markup) ──────────────────── --}}
    @if (! $isCartEmpty)
    <div id="cartpage-shell">
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_420px]">

        {{-- Left column: items --}}
        <div class="flex min-w-0 flex-col gap-4">

          {{-- Toolbar --}}
          <div class="flex items-center justify-between gap-3 rounded-2xl border border-buyer-line bg-white px-5 py-3.5">
            <label class="flex cursor-pointer items-center gap-2.5" for="cartpage-select-all">
              <input
                type="checkbox"
                id="cartpage-select-all"
                class="js-cartpage-select-all h-4 w-4 cursor-pointer rounded accent-primary-800"
                aria-label="Select every item in your cart"
                checked
              >
              <span class="text-[13.5px] font-semibold text-buyer-ink">
                Select all
                <span class="font-normal text-neutral-400" id="cartpage-select-all-count">
                  ({{ $availableLines }} {{ Str::plural('item', $availableLines) }})
                </span>
              </span>
            </label>
            <button
              type="button"
              id="cartpage-remove-selected"
              class="flex shrink-0 items-center gap-1.5 text-[12.5px] font-semibold text-neutral-400 transition-colors hover:text-primary-700 disabled:pointer-events-none disabled:opacity-40"
              disabled
              aria-label="Remove the selected items"
            >
              <i data-lucide="trash-2" width="14" height="14"></i>
              Remove selected
            </button>
          </div>

          {{-- Seller groups --}}
          @foreach ($summary->sellerGroups as $group)
            @include('Buyer.Cart._seller-group', [
                'seller' => $group['seller'],
                'items'  => $group['items'],
            ])
          @endforeach

          {{-- Unavailable items --}}
          @if ($summary->unavailableItems->isNotEmpty())
            <div class="rounded-2xl border border-buyer-line bg-white" id="cartpage-unavailable-group">
              <div class="border-b border-buyer-line px-5 py-4">
                <p class="text-[12.5px] font-semibold tracking-[0.08em] text-neutral-400 uppercase">No longer available</p>
              </div>
              @foreach ($summary->unavailableItems as $item)
                @include('Buyer.Cart._item', ['item' => $item, 'unavailable' => true])
              @endforeach
            </div>
          @endif

        </div>

        {{-- Right column: summary (sticky on lg+) --}}
        @include('Buyer.Cart._summary', ['summary' => $summary, 'voucher' => $voucher])

      </div>

      {{-- Mobile sticky checkout bar — only inside the populated cart, and
           only below lg. (The previous version also carried `hidden`, which
           beat `lg:hidden` and meant it never rendered at all.) --}}
      <div id="cartpage-mobile-bar"
           class="fixed bottom-0 left-0 right-0 z-40 border-t border-buyer-line bg-white px-4 py-3 lg:hidden"
           aria-live="polite">
        <div class="mx-auto flex max-w-[640px] items-center justify-between gap-4">
          <div>
            <p class="text-[11px] text-neutral-400">Total</p>
            <p id="cartpage-mobile-total" class="font-serif text-[20px] font-semibold text-buyer-ink">
              {{ $summary->formattedTotal() }}
            </p>
          </div>
          <button
            type="button"
            class="js-cartpage-checkout-btn flex-1 rounded-xl bg-primary-800 py-3.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-40"
            @disabled($summary->subtotalCentavos === 0)
          >
            Proceed to Checkout →
          </button>
        </div>
      </div>
    </div>
    @endif

    {{-- ── Empty cart ────────────────────────────────────────────────────── --}}
    <div id="cartpage-empty-slot" class="{{ $isCartEmpty ? '' : 'hidden' }}">
      @include('Buyer.Cart._empty')
    </div>

  </div>
</div>
@endsection

{{-- Pass server-side data to cart.js via a JSON island. URLs are built from
     named routes so the shared JS never hardcodes a path. __ID__ is replaced
     with the cart item id at call time. --}}
@push('scripts')
<script id="cartpage-data" type="application/json">
{
  "summaryUrl":       "{{ route('buyer.cart.summary') }}",
  "updateUrl":        "{{ route('buyer.cart.update', ['id' => '__ID__']) }}",
  "destroyUrl":       "{{ route('buyer.cart.destroy', ['id' => '__ID__']) }}",
  "bulkDestroyUrl":   "{{ route('buyer.cart.bulk-destroy') }}",
  "voucherApplyUrl":  "{{ route('buyer.cart.voucher.apply') }}",
  "voucherRemoveUrl": "{{ route('buyer.cart.voucher.remove') }}",
  "checkoutUrl":      "{{ route('buyer.checkout.index') }}",
  "csrfToken":        "{{ csrf_token() }}",
  "initialTotal":     "{{ $summary->formattedTotal() }}"
}
</script>
@vite(['resources/js/buyer/cart.js'])
@endpush
