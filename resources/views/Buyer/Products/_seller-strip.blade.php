{{--
    PDP Seller strip partial.
    Expects: $sellerData (array with name, initials, badge, rating, followers)
--}}
<div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-buyer-line bg-white px-5 py-4">

  {{-- Avatar + info --}}
  <div class="flex items-center gap-3">
    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-buyer-ink font-serif text-[13px] font-bold text-white">
      {{ $sellerData['initials'] }}
    </div>
    <div>
      <div class="flex items-center gap-2">
        <span class="font-serif text-[15px] font-semibold text-buyer-ink">{{ $sellerData['name'] }}</span>
        {{-- Premium badge --}}
        <span class="rounded-full bg-tertiary-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.06em] text-tertiary-700">
          {{ $sellerData['badge'] }}
        </span>
      </div>
      <div class="mt-0.5 flex items-center gap-2 text-[11.5px] text-neutral-400">
        <span class="flex items-center gap-0.5">
          <i class="fa-solid fa-star text-[9px] text-buyer-star"></i>
          {{ number_format($sellerData['rating'], 1) }}
        </span>
        <span class="text-neutral-200">·</span>
        <span>{{ $sellerData['followers'] }} followers</span>
      </div>
    </div>
  </div>

  {{-- Actions --}}
  <div class="flex gap-2">
    <button
      type="button"
      onclick="window.toggleChat()"
      class="inline-flex items-center gap-1.5 rounded-full border border-buyer-line bg-white px-4 py-2 text-[12px] font-semibold text-buyer-ink transition-colors hover:bg-secondary-50"
    >
      <i data-lucide="message-circle" width="14" height="14"></i>
      Chat with Seller
    </button>
    <a
      href="#"
      class="inline-flex items-center gap-1.5 rounded-full bg-buyer-ink px-4 py-2 text-[12px] font-semibold text-white transition-opacity hover:opacity-90"
    >
      <i data-lucide="store" width="14" height="14"></i>
      Visit Boutique
    </a>
  </div>

</div>
