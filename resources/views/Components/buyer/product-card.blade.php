{{--
    Buyer catalog card — a copy of the landing-page card's UI
    (resources/views/Components/product-card.blade.php), so the buyer home
    grids and the PDP related-products carousel share the landing look:
    hairline border + 10px radius, hover lift, portrait image with a hover
    "QUICK VIEW" overlay, SALE badge, price row and a full-width outlined
    ADD button. Only the data binding and the behaviour hooks differ.

    Card content (Shopee-style buyer catalogue tile):
      name (top) · price on the left with the sold count in small type on its
      right · then a single delivery-estimate + shop-location line that
      truncates instead of wrapping

    Props (all optional):
      $product        — Product model instance. Callers MUST eager-load `seller`
                        (HomeController and ProductController both do) because the
                        shop location is read from it — no lazy N+1 here.
      $discountBadge  — string e.g. "40%" shown as the SALE badge top-left; omit to hide
      $oldPrice       — string struck-through price; omit to hide the row.
                        `products` has no compare-at column yet, so callers
                        currently omit it — never fabricate an old price.
      $soldCount      — int units sold; overrides the derived demo value.
                        TODO: `products` has no sold column and no order-status
                        rule is agreed yet, so every card currently shows a demo
                        figure derived from the product id (developer-approved).
      $deliveryDays   — string estimate e.g. "3–5 days"; overrides the derived
                        demo value. TODO: nothing in products/sellers feeds this
                        yet, so cards show a demo range derived from the product
                        id (developer-approved) until the delivery phase exists.
      $url            — string URL to the PDP; when provided the image and title
                        link to the PDP. The QUICK VIEW button always opens the
                        quick-view modal. When omitted (skeleton cards) the
                        image is a plain box.

    Behaviour hooks are kept verbatim — buyer.js / home.js bind these:
      `.js-open-qv`  + data-product-id → quick-view modal
      `.wish-toggle` (+ `.active`)     → wishlist heart

    Add-to-cart deliberately does NOT live on this card any more; it stays
    available in the quick-view modal and on the PDP buy-box.
--}}
@php
    $product       = $product       ?? null;
    $discountBadge = $discountBadge ?? null;
    $oldPrice      = $oldPrice      ?? null;
    $url           = $url           ?? null;
    $soldCount     = $soldCount     ?? null;
    $deliveryDays  = $deliveryDays  ?? null;
    $name          = $product?->name             ?? 'Product coming soon';
    $price         = $product?->formattedPrice() ?? '₱—';
    $imageUrl      = $product?->catalogImageUrl();
    $productId     = $product?->id;

    // Shop location — read from the eager-loaded seller's registered address.
    // "Municipality, Province" when both exist, whichever exists otherwise.
    $seller = $product?->seller;
    $place  = $seller !== null
        ? array_filter([$seller->municipality_name, $seller->province_name])
        : [];
    $shopLocation = $place !== [] ? implode(', ', $place) : null;

    // ── DEMO "sold" + delivery estimate (developer-approved 2026-09-22) ──────
    // `products` has no sold column and the delivery/logistics phase does not
    // exist yet. Both values are therefore DERIVED FROM THE PRODUCT ID: stable
    // for a given product (never changes between requests) and varied across
    // products — the same derived-value approach as store-card.blade.php's
    // star rating. A caller-supplied $soldCount / $deliveryDays always wins.
    // TODO: replace with SUM(order_items.quantity) and a real lead-time column.
    if ($productId !== null) {
        $soldCount    = $soldCount    ?? (($productId * 47) % 1200) + 30;
        $deliveryDays = $deliveryDays ?? ['2–4 days', '3–5 days', '4–7 days'][$productId % 3];
    }
@endphp
<article class="group flex h-full w-full max-w-[320px] flex-col overflow-hidden rounded-[10px] border border-line bg-white transition-[box-shadow,transform] duration-[250ms] hover:-translate-y-1 hover:shadow-[0_16px_32px_rgba(41,38,38,0.12)]">
    {{-- Image: exact 4:3 — at the 320px tile width that is 320 × 240, keeping the
         picture the dominant block (≈240px image vs ≈150px body ⇒ ≈390px card).
         16:9 was rejected: it pins the image to only 180px at this width, which
         made the card look body-heavy.
         The inner box fills the frame instead of the landing card's nested
         aspect quirk, so the ratio is exact with no bottom clipping. --}}
    <div class="relative block aspect-[4/3] overflow-hidden bg-blush">
        {{-- Image — the landing card's image box; a PDP link when $url is provided --}}
        @if ($url)
            <a href="{{ $url }}"
               class="block h-full w-full bg-secondary-300 bg-cover bg-center bg-no-repeat"
               @if ($imageUrl) style="background-image: url('{{ $imageUrl }}')" @endif
               aria-label="View {{ $name }}"></a>
        @else
            <div class="block h-full w-full bg-secondary-300 bg-cover bg-center bg-no-repeat"
                 @if ($imageUrl) style="background-image: url('{{ $imageUrl }}')" @endif
                 role="img" aria-label="{{ $name }}"></div>
        @endif

        @unless ($imageUrl)
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center text-primary-800/40">
                <i data-lucide="package" width="28" height="28"></i>
            </div>
        @endunless

        {{-- Hover overlay — QUICK VIEW, opens the global quick-view modal (buyer.js).
             `pointer-events-none` + `group-hover:pointer-events-auto` keeps the
             invisible overlay from stealing clicks from the PDP link below it. --}}
        <div class="pointer-events-none absolute bottom-0 left-0 right-0 translate-y-2 bg-[linear-gradient(180deg,rgba(30,27,27,0)_0%,rgba(30,27,27,0.55)_100%)] p-3.5 opacity-0 transition-[opacity,transform] duration-[250ms] group-hover:pointer-events-auto group-hover:translate-y-0 group-hover:opacity-100">
            <button type="button"
                    class="js-open-qv inline-flex h-[42px] w-full cursor-pointer items-center justify-center rounded border-0 bg-white text-xs font-semibold tracking-[1.4px] text-neutral-950 transition-colors duration-200 hover:bg-primary-800 hover:text-white"
                    @if ($productId) data-product-id="{{ $productId }}" @endif>
                <div>QUICK VIEW</div>
            </button>
        </div>

        {{-- Badge — top-left, only when $discountBadge is set (landing SALE colours) --}}
        @if ($discountBadge)
            <div class="pointer-events-none absolute left-3.5 top-3.5 z-[1] rounded bg-[#d14a5f] px-3 py-1.5">
                <div class="font-sans text-[11px] font-semibold tracking-[1.4px] text-white">{{ $discountBadge }}</div>
            </div>
        @endif

        {{-- Wishlist heart — buyer-area affordance kept on top of the landing design --}}
        <button type="button"
                class="wish-toggle absolute top-3.5 right-3.5 z-[1] flex h-8 w-8 items-center justify-center rounded-full border-0 bg-white/90 text-[13px] text-neutral-500 transition-colors [&.active]:text-buyer-wish"
                aria-label="Save {{ $name }} to wishlist">
            <i class="fa-solid fa-heart"></i>
        </button>
    </div>

    {{-- Pink body (landing page's placeholder pink, `bg-secondary-300` #f7d6d0):
         keeps the card from blending into the cream page background. --}}
    <div class="flex flex-1 flex-col gap-3.5 bg-secondary-300 px-5 pb-5 pt-5 max-sm:gap-3 max-sm:px-3.5 max-sm:pb-4 max-sm:pt-3.5">
        <div>
            {{-- Title — PDP link when $url provided, plain heading otherwise --}}
            @if ($url)
                <a href="{{ $url }}"
                   class="line-clamp-2 font-sans text-sm font-semibold leading-[1.5] text-neutral-950 hover:underline hover:underline-offset-2 max-sm:text-[13px]">{{ $name }}</a>
            @else
                <div class="line-clamp-2 font-sans text-sm font-semibold leading-[1.5] text-neutral-950 max-sm:text-[13px]">{{ $name }}</div>
            @endif
        </div>

        {{-- Price + sold, Shopee-style: price on the left, sold count in small
             type on its right. --}}
        <div class="flex items-baseline justify-between gap-2">
            @if ($oldPrice)
                <div class="flex items-baseline gap-2.5">
                    <div class="font-sans text-base font-bold text-primary-800 max-sm:text-sm">{{ $price }}</div>
                    <div class="font-sans text-sm text-price-muted line-through">{{ $oldPrice }}</div>
                </div>
            @else
                <div class="font-sans text-base font-bold text-neutral-950 max-sm:text-sm">{{ $price }}</div>
            @endif

            @if ($soldCount !== null)
                <span class="shrink-0 font-sans text-xs leading-[1.4] text-neutral-950">{{ number_format($soldCount) }} sold</span>
            @endif
        </div>

        {{-- Delivery + location, pinned to the card's bottom: `mt-auto` keeps it
             at the base when a taller card in the same grid row stretches this
             one. Card height is content-driven on purpose — a forced min-height
             made the 16:9 image look small beside an almost-empty body
             (320px wide ⇒ the image is only 180px tall).
             Location is REAL (from the seller); sold + delivery are DEMO values
             derived from the product id — see the @php block above.
             NOTE: each <span> carries its own `text-neutral-950` utility. app.css
             @layer base sets `color: var(--text-secondary)` on span/button
             directly, and a direct declaration beats an inherited one (see
             memory-bank/mistakes.md), so a bare span washes out on the pink
             body. The price is never truncated — money must stay fully visible. --}}
        @if ($deliveryDays || $shopLocation)
            <div class="mt-auto flex min-w-0 items-center gap-2 text-xs leading-[1.4] text-neutral-950 [&_svg]:shrink-0">
                @if ($deliveryDays)
                    <i data-lucide="truck" width="13" height="13"></i>
                    <span class="shrink-0 text-neutral-950">Est. {{ $deliveryDays }}</span>
                @endif

                @if ($deliveryDays && $shopLocation)
                    {{-- Separator between the estimate and the location --}}
                    <span class="h-3 w-px shrink-0 bg-buyer-ink/25" aria-hidden="true"></span>
                @endif

                @if ($shopLocation)
                    <i data-lucide="map-pin" width="13" height="13"></i>
                    {{-- Truncated on purpose ("even if it's cut off"); the
                         title attribute still reveals the full location. --}}
                    <span class="min-w-0 truncate text-neutral-950" title="{{ $shopLocation }}">{{ $shopLocation }}</span>
                @endif
            </div>
        @endif
    </div>
</article>
