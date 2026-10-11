{{--
    PDP Tabs + Details panel partial.
    Expects: $description (string|null)
--}}
<div class="mt-8">

  {{-- Product Details / Description --}}
  <div class="prose prose-buyer max-w-none text-[14px] leading-relaxed text-buyer-ink-soft">
    {!! nl2br(e($description)) !!}
  </div>

</div>
