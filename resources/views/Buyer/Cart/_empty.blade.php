{{--
    Empty-cart state.

    Rendered inside #cartpage-empty-slot (hidden while the cart has items).
    When the last line is removed, cart.js reloads the page and the server
    renders this state — the markup only ever exists once in the page.
--}}
<div class="flex flex-col items-center justify-center px-4 py-20 text-center">
  <span class="flex h-24 w-24 items-center justify-center rounded-full bg-blush text-primary-400">
    <i data-lucide="shopping-cart" width="36" height="36"></i>
  </span>

  <h2 class="mt-6 font-serif text-[26px] font-semibold text-buyer-ink">Your cart is empty</h2>

  <p class="mt-2 max-w-[380px] text-[13px] text-neutral-400">
    Looks like you haven't added anything yet — browse the marketplace and find something you love.
  </p>

  <a href="{{ route('buyer.home') }}"
     class="mt-6 rounded-xl bg-primary-800 px-7 py-3.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90">
    Start Shopping
  </a>
</div>