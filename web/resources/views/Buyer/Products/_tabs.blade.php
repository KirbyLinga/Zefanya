{{--
    PDP Tabs + Details panel partial.
    Expects: $description, $features (array of icon/title/text), $reviewCount
--}}
<div class="mt-8">

  {{-- Tab list --}}
  <div class="relative">
    <div class="overflow-x-auto">
      <div class="flex min-w-max border-b border-buyer-line" role="tablist" aria-label="Product information">
        <button type="button" role="tab" id="tab-details"
          aria-selected="true" aria-controls="panel-details"
          class="js-pdp-tab pdp-tab-btn selected whitespace-nowrap px-5 py-3 text-[12.5px] font-semibold text-buyer-ink transition-colors
            [&.selected]:border-b-2 [&.selected]:border-buyer-ink [&.selected]:-mb-px
            text-neutral-400 [&.selected]:text-buyer-ink">
          Product Details &amp; Fabric Care
        </button>
        <button type="button" role="tab" id="tab-sizing"
          aria-selected="false" aria-controls="panel-sizing"
          class="js-pdp-tab pdp-tab-btn whitespace-nowrap px-5 py-3 text-[12.5px] font-semibold transition-colors
            [&.selected]:border-b-2 [&.selected]:border-buyer-ink [&.selected]:-mb-px
            text-neutral-400 [&.selected]:text-buyer-ink">
          Size &amp; Measurements Guide
        </button>
        <button type="button" role="tab" id="tab-shipping"
          aria-selected="false" aria-controls="panel-shipping"
          class="js-pdp-tab pdp-tab-btn whitespace-nowrap px-5 py-3 text-[12.5px] font-semibold transition-colors
            [&.selected]:border-b-2 [&.selected]:border-buyer-ink [&.selected]:-mb-px
            text-neutral-400 [&.selected]:text-buyer-ink">
          Shipping &amp; Returns Delivery
        </button>
        <a href="#pdp-reviews" role="tab" id="tab-reviews"
          class="pdp-tab-btn whitespace-nowrap px-5 py-3 text-[12.5px] font-semibold text-neutral-400 transition-colors hover:text-buyer-ink">
          Customer Experiences ({{ number_format($reviewCount) }})
        </a>
      </div>
    </div>
  </div>

  {{-- ── Panel: Product Details ── --}}
  <div id="panel-details" role="tabpanel" aria-labelledby="tab-details" class="pdp-panel mt-8">
    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[1fr_280px]">

      {{-- Left: heading + description + features --}}
      <div>
        <h2 class="mb-4 font-serif text-[22px] font-semibold text-buyer-ink sm:text-[26px]">
          The Anatomy of Soft Tailoring
        </h2>
        <p class="mb-8 max-w-[600px] text-[14px] leading-relaxed text-buyer-ink-soft">
          {{ $description }}
        </p>

        {{-- 2×2 feature grid --}}
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
          @foreach ($features as $feat)
            <div class="flex gap-3">
              <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-secondary-100 text-primary-700">
                <i data-lucide="{{ $feat['icon'] }}" width="15" height="15"></i>
              </div>
              <div>
                <p class="text-[12.5px] font-semibold text-buyer-ink">{{ $feat['title'] }}</p>
                <p class="mt-0.5 text-[12px] leading-relaxed text-neutral-400">{{ $feat['text'] }}</p>
              </div>
            </div>
          @endforeach
        </div>
      </div>

      {{-- Right: side image with caption --}}
      <div class="flex flex-col gap-2">
        <div class="aspect-[4/5] overflow-hidden rounded-2xl bg-secondary-200">
          {{-- TODO: use a real product lifestyle image --}}
          <div class="flex h-full w-full items-center justify-center text-primary-300">
            <i data-lucide="image" width="40" height="40"></i>
          </div>
        </div>
        <p class="text-center text-[11px] italic text-neutral-400">Hand-finished by master artisans</p>
      </div>
    </div>
  </div>

  {{-- ── Panel: Size & Measurements ── --}}
  <div id="panel-sizing" role="tabpanel" aria-labelledby="tab-sizing" class="pdp-panel mt-8 hidden">
    <h2 class="mb-4 font-serif text-[22px] font-semibold text-buyer-ink">Size &amp; Measurements Guide</h2>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[480px] border-collapse text-[13px]">
        <thead>
          <tr class="border-b border-buyer-line">
            <th class="py-2.5 pr-6 text-left font-semibold text-buyer-ink">Size</th>
            <th class="py-2.5 pr-6 text-left font-semibold text-buyer-ink">Chest (cm)</th>
            <th class="py-2.5 pr-6 text-left font-semibold text-buyer-ink">Waist (cm)</th>
            <th class="py-2.5 text-left font-semibold text-buyer-ink">Length (cm)</th>
          </tr>
        </thead>
        <tbody class="text-neutral-500">
          @foreach (['XS' => [82,62,108], 'S' => [86,66,110], 'M' => [90,70,112], 'L' => [94,74,114], 'XL' => [98,78,116]] as $sz => $dims)
          <tr class="border-b border-buyer-line/60">
            <td class="py-2.5 pr-6 font-semibold text-buyer-ink">{{ $sz }}</td>
            <td class="py-2.5 pr-6">{{ $dims[0] }}</td>
            <td class="py-2.5 pr-6">{{ $dims[1] }}</td>
            <td class="py-2.5">{{ $dims[2] }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <p class="mt-4 text-[12px] text-neutral-400">Measurements are of the garment, not the body. We recommend sizing up for a relaxed fit.</p>
  </div>

  {{-- ── Panel: Shipping & Returns ── --}}
  <div id="panel-shipping" role="tabpanel" aria-labelledby="tab-shipping" class="pdp-panel mt-8 hidden">
    <h2 class="mb-6 font-serif text-[22px] font-semibold text-buyer-ink">Shipping &amp; Returns Delivery</h2>
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      @foreach ([
        ['icon' => 'truck',      'title' => 'Standard Shipping',   'text' => '5–7 business days · Free on orders over $120'],
        ['icon' => 'zap',        'title' => 'Express Delivery',     'text' => '2–3 business days · $12 flat rate'],
        ['icon' => 'refresh-cw', 'title' => 'Easy Returns',         'text' => '14-day hassle-free returns. Item must be unworn with tags.'],
      ] as $item)
      <div class="rounded-2xl border border-buyer-line bg-white p-5">
        <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-xl bg-secondary-100 text-primary-700">
          <i data-lucide="{{ $item['icon'] }}" width="16" height="16"></i>
        </div>
        <p class="text-[13px] font-semibold text-buyer-ink">{{ $item['title'] }}</p>
        <p class="mt-1 text-[12px] leading-relaxed text-neutral-400">{{ $item['text'] }}</p>
      </div>
      @endforeach
    </div>
  </div>

</div>
