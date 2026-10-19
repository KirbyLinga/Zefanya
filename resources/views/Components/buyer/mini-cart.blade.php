{{-- Mini cart dropdown for navbar --}}
<div id="miniCartDropdown" class="absolute top-[calc(100%+8px)] right-0 z-50 hidden w-96 max-h-96 flex-col overflow-hidden rounded-xl border border-line bg-white shadow-lg [&.open]:flex">
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h3 class="font-sans font-semibold text-buyer-ink">Your Cart</h3>
        <button type="button" class="text-[13px] font-semibold text-buyer-ink-soft hover:text-buyer-ink" onclick="document.getElementById('miniCartDropdown').classList.remove('open')">Close</button>
    </div>

    {{-- Items list --}}
    <div id="miniCartItems" class="flex-1 overflow-y-auto">
        {{-- Will be populated by AJAX --}}
        <div class="flex items-center justify-center h-full">
            <p class="text-[12px] text-buyer-ink-soft">Loading...</p>
        </div>
    </div>

    {{-- Footer with totals and buttons --}}
    <div class="border-t border-line bg-gray-50 px-4 py-3">
        <div class="mb-3 space-y-1 text-[13px]">
            <div class="flex justify-between text-buyer-ink-soft">
                <span>Subtotal</span>
                <span id="miniCartSubtotal">₱0.00</span>
            </div>
            <div class="flex justify-between font-semibold text-buyer-ink">
                <span>Total</span>
                <span id="miniCartTotal">₱0.00</span>
            </div>
        </div>
        <div class="space-y-2">
            <a href="{{ route('buyer.checkout.index') }}" class="block w-full rounded-lg bg-buyer-ink px-4 py-2.5 text-center text-[13px] font-semibold text-white hover:opacity-90 transition-opacity">
                Checkout
            </a>
            <a href="{{ route('buyer.cart.index') }}" class="block w-full rounded-lg border border-line px-4 py-2.5 text-center text-[13px] font-semibold text-buyer-ink hover:bg-gray-50 transition-colors">
                View Cart
            </a>
        </div>
    </div>
</div>
