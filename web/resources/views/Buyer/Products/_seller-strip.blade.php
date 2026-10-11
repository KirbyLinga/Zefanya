{{--
    PDP Seller strip partial.
    Expects: $sellerData (array with name, initials)
--}}
<div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-buyer-line bg-white px-5 py-4">

  {{-- Avatar + info --}}
  <div class="flex items-center gap-3">
    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-buyer-ink font-serif text-[13px] font-bold text-white">
      {{ $sellerData['initials'] }}
    </div>
    <div>
      <span class="font-serif text-[15px] font-semibold text-buyer-ink">{{ $sellerData['name'] }}</span>
    </div>
  </div>

</div>
