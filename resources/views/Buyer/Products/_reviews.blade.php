{{--
    PDP Reviews partial.
    Expects: $rating, $reviewCount, $ratingBreakdown (array 5→1 of percentages), $reviews
--}}
<section id="pdp-reviews" class="mt-12 scroll-mt-20">
  <h2 class="mb-6 font-serif text-[22px] font-semibold text-buyer-ink sm:text-[26px]">
    Customer Experiences
  </h2>

  <div class="grid grid-cols-1 gap-6 lg:grid-cols-[260px_1fr]">

    {{-- ── Summary card ── --}}
    <div class="flex flex-col gap-5">
      <div class="rounded-2xl border border-buyer-line bg-white p-5">
        {{-- Big score --}}
        <div class="mb-1 font-serif text-[52px] font-semibold leading-none text-buyer-ink">
          {{ number_format($rating, 1) }}
        </div>
        {{-- Stars --}}
        <div class="mb-2 flex items-center gap-1">
          @for ($s = 1; $s <= 5; $s++)
            <i class="fa-solid fa-star text-[13px] text-buyer-star"></i>
          @endfor
        </div>
        <p class="text-[11.5px] text-neutral-400">
          {{ number_format($reviewCount) }} Verified Reviews
        </p>
        <p class="mt-0.5 text-[11.5px] font-semibold text-tertiary-600">
          98% Recommend this product
        </p>

        {{-- Breakdown bars --}}
        <div class="mt-4 flex flex-col gap-2">
          @foreach ($ratingBreakdown as $star => $pct)
            <div class="flex items-center gap-2 text-[11px] text-neutral-400">
              <span class="w-3 shrink-0 text-right">{{ $star }}</span>
              <i class="fa-solid fa-star shrink-0 text-[9px] text-buyer-star"></i>
              <div class="relative h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100">
                <div class="absolute left-0 top-0 h-full rounded-full bg-primary-400 transition-all duration-500"
                     style="width: {{ $pct }}%"></div>
              </div>
              <span class="w-7 shrink-0 text-right">{{ $pct }}%</span>
            </div>
          @endforeach
        </div>
      </div>

      {{-- Write a review CTA --}}
      <div class="rounded-2xl border border-buyer-line bg-secondary-50 p-5 text-center">
        <p class="mb-1 font-serif text-[15px] font-semibold text-buyer-ink">Shared Your Fit?</p>
        <p class="mb-4 text-[12px] text-neutral-400">Share your style and help the community</p>
        <button
          type="button"
          class="w-full rounded-full border border-buyer-ink py-2.5 text-[12px] font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white"
        >
          Write an Atelier Review
        </button>
      </div>
    </div>

    {{-- ── Review cards ── --}}
    <div class="flex flex-col gap-4">
      @foreach ($reviews as $review)
        <div class="rounded-2xl border border-buyer-line bg-white p-5">
          {{-- Reviewer header --}}
          <div class="mb-3 flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
              {{-- Avatar initial --}}
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-secondary-200 font-sans text-[13px] font-semibold text-buyer-ink">
                {{ strtoupper(substr($review['name'], 0, 1)) }}
              </div>
              <div>
                <p class="text-[13px] font-semibold text-buyer-ink">{{ $review['name'] }}</p>
                <div class="flex items-center gap-2">
                  @if ($review['verified'])
                    <span class="text-[10.5px] font-semibold text-tertiary-600">Verified Purchase</span>
                    <span class="text-neutral-200">·</span>
                  @endif
                  <span class="text-[10.5px] text-neutral-400">{{ $review['date'] }}</span>
                </div>
              </div>
            </div>
            {{-- Stars --}}
            <div class="flex shrink-0 items-center gap-0.5">
              @for ($s = 1; $s <= 5; $s++)
                <i class="fa-solid fa-star text-[10px] {{ $s <= $review['rating'] ? 'text-buyer-star' : 'text-neutral-200' }}"></i>
              @endfor
            </div>
          </div>
          <p class="text-[13px] leading-relaxed text-buyer-ink-soft">{{ $review['text'] }}</p>
          {{-- Optional photo --}}
          @if ($review['photo'])
            <div class="mt-3">
              <img src="{{ $review['photo'] }}" alt="Review photo" class="h-20 w-20 rounded-xl object-cover">
            </div>
          @endif
        </div>
      @endforeach
    </div>

  </div>
</section>
