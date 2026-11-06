{{--
    A shop's claimable vouchers. Renders nothing when there are none.
    Expects: $offers (Collection of Voucher)
    Optional: $applyInCart (bool) adds an Apply button that cart.js handles;
    otherwise a signed-in buyer gets a Claim button.
--}}
@if ($offers->isNotEmpty())
  @php
    $walletBuyerId = auth('buyer')->id();
    $claimedIds = $walletBuyerId
        ? \App\Models\Shared\VoucherClaim::query()->where('buyer_id', $walletBuyerId)->whereIn('voucher_id', $offers->modelKeys())->pluck('voucher_id')->all()
        : [];
  @endphp
  <section aria-label="Shop vouchers" class="{{ $wrapperClass ?? '' }}">
    <p class="mb-2 text-[11.5px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Shop vouchers</p>
    <ul class="flex flex-col gap-2">
      @foreach ($offers as $offer)
        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed border-primary-300 bg-primary-50 px-3 py-2">
          <div class="min-w-0">
            <p class="select-all font-mono text-[13px] font-semibold text-buyer-ink">{{ $offer->code }}</p>
            <p class="text-[11.5px] text-buyer-ink-soft">{{ $offer->offerSummary() }}</p>
          </div>
          @if ($applyInCart ?? false)
            <button type="button"
                    class="js-cartpage-shop-voucher-apply shrink-0 rounded-md border border-primary-800 px-3 py-1 text-[12px] font-semibold text-primary-800 transition-colors hover:bg-primary-100"
                    data-code="{{ $offer->code }}">
              Apply
            </button>
          @elseif (in_array($offer->id, $claimedIds, true))
            <a href="{{ route('buyer.vouchers.index') }}" class="shrink-0 text-[12px] font-semibold text-tertiary-700 hover:underline">In your vouchers</a>
          @elseif ($walletBuyerId)
            <form method="POST" action="{{ route('buyer.vouchers.claim', $offer) }}" class="shrink-0">
              @csrf
              <button type="submit" class="rounded-md border border-primary-800 px-3 py-1 text-[12px] font-semibold text-primary-800 transition-colors hover:bg-primary-100">Claim</button>
            </form>
          @endif
        </li>
      @endforeach
    </ul>
  </section>
@endif
