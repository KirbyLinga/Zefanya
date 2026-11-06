{{--
    PDP Seller strip partial.
    Expects: $sellerData (array with name, initials)
--}}
<div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-buyer-line bg-white px-5 py-4">

  {{-- Avatar + info --}}
  <div class="flex min-w-0 items-center gap-3">
    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-buyer-ink font-serif text-[13px] font-bold text-white">
      {{ $sellerData['initials'] }}
    </div>
    <div class="min-w-0">
      <p class="text-[11px] font-semibold uppercase tracking-[1px] text-buyer-ink-soft">Sold by</p>
      <p class="break-words font-serif text-[15px] font-semibold text-buyer-ink">{{ $sellerData['name'] }}</p>
      @if ($product->seller)
        <a href="{{ route('buyer.stores.show', $product->seller) }}" class="inline-flex items-center gap-1 text-[12.5px] font-semibold text-primary-800 no-underline hover:underline">
          Visit store <i data-lucide="arrow-right" width="13" height="13" aria-hidden="true"></i>
        </a>
      @endif
    </div>
  </div>

  {{-- A guest's request answers 401, and product-show.js then opens the login modal. --}}
  @if ($product->seller)
    <button
      type="button"
      id="pdp-chat-seller"
      class="inline-flex items-center justify-center gap-2 rounded-full border border-buyer-line bg-white px-5 py-2.5 text-[13px] font-semibold text-buyer-ink transition-colors hover:bg-secondary-100"
      data-url="{{ route('buyer.messages.getOrCreate') }}"
      data-seller-id="{{ $product->seller->id }}"
      data-product-id="{{ $product->id }}"
    >
      <i data-lucide="message-circle" width="15" height="15" aria-hidden="true"></i>
      {{ Auth::guard('buyer')->check() ? 'Message seller' : 'Log in to message seller' }}
    </button>
  @endif

</div>
