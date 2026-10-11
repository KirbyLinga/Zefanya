{{-- resources/views/Buyer/Layouts/footer.blade.php
     ============================================================================
     Converted to Tailwind (Buyer phase, chunk a — dev approved).
     Values are 1:1 with the old buyer.css footer rules:
       footer            → bg-buyer-ink pt-12 pb-[22px] (--ink #3A2529, #EADFDC)
       .container        → mx-auto max-w-[1200px] px-8
       .footer-grid      → grid-cols-[1.6fr_1fr_1fr] gap-8 mb-8
                           @≤980px → 2 columns
       .foot-logo        → Playfair 21px, white, mb 10px
       .foot-desc        → 12.5px, #C9B9B6, line-height 1.6, max-width 280px
       footer h4         → Playfair (inherited, NOT font-sans), 13px/600,
                           letter-spacing .03em, white, mb 14px
       footer ul         → full-column flex, gap 9px, no markers
       footer a          → 12.5px, #C9B9B6 (no hover state was defined)
       .footer-bottom    → space-between, wrap, gap 10px, 1px #4d3a3e top rule,
                           pt 18px, 11.5px, #C9B9B6

     CAVEAT: buyer.css styles this footer partly through ELEMENT selectors
     (footer / footer h4 / footer ul / footer a). That block is an inert
     duplicate with identical values and MUST be removed at buyer wrap-up.
     The utilities below are self-sufficient for that moment.
     ============================================================================ --}}

<footer class="w-full bg-buyer-ink text-buyer-footer-text">
  <div class="mx-auto max-w-[1200px] px-8 pt-12 pb-[22px]">
    <div class="grid grid-cols-[1.6fr_1fr_1fr_1fr] gap-8 mb-8 max-[980px]:grid-cols-2">
      <div>
        <div class="mb-2.5 font-serif text-[21px] text-white">Zefanya</div>
        {{-- NOTE: Tailwind preflight (`*{margin:0}`) zeroes the UA paragraph
             margin this <p> used to have. In the original it collapsed with
             the logo's 10px bottom margin to 12.5px (UA 1em at 12.5px), so
             that gap is re-stated explicitly to stay pixel-identical. Safe to
             drop if the tighter preflight spacing is preferred. --}}
        <p class="mt-[12.5px] mb-[12.5px] max-w-[280px] text-[12.5px] leading-[1.6] text-buyer-footer-muted">Everything you need, all in one place — from daily essentials to unexpected finds, delivered straight to your door.</p>
      </div>
      <div>
        <h4 class="mt-0 mb-3.5 text-[13px] font-semibold tracking-[0.03em] text-white">Shop</h4>
        <ul class="m-0 flex list-none flex-col gap-[9px] p-0">
          <li><a href="{{ route('buyer.categories.index') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">All categories</a></li>
          <li><a href="{{ route('buyer.products.index') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">All products</a></li>
          <li><a href="{{ route('buyer.pages.about') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">About us</a></li>
        </ul>
      </div>
      <div>
        <h4 class="mt-0 mb-3.5 text-[13px] font-semibold tracking-[0.03em] text-white">Help</h4>
        <ul class="m-0 flex list-none flex-col gap-[9px] p-0">
          <li><a href="{{ route('buyer.pages.contact') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">Contact us</a></li>
          <li><a href="{{ route('buyer.pages.shipping-returns') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">Shipping & Returns</a></li>
          <li><a href="{{ route('buyer.help.index') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">Help & Support</a></li>
        </ul>
      </div>
      <div>
        <h4 class="mt-0 mb-3.5 text-[13px] font-semibold tracking-[0.03em] text-white">Legal</h4>
        <ul class="m-0 flex list-none flex-col gap-[9px] p-0">
          <li><a href="{{ route('buyer.pages.privacy-policy') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">Privacy Policy</a></li>
          <li><a href="{{ route('buyer.pages.terms-of-service') }}" class="text-[12.5px] text-buyer-footer-muted hover:text-white transition-colors">Terms of Service</a></li>
        </ul>
      </div>
    </div>
    <div class="flex flex-wrap justify-between gap-2.5 border-t border-buyer-footer-rule pt-[18px] text-[11.5px]">
      {{-- Colour is set directly on each span because app.css base styles
           `span { color: var(--text-secondary) }` directly — an inherited
           colour from the wrapper would lose to that rule. --}}
      <span class="text-buyer-footer-muted">© {{ date('Y') }} Zefanya. All rights reserved.</span>
      <span class="text-buyer-footer-muted">Made for buyers who love finding the perfect thing.</span>
    </div>
  </div>
</footer>
