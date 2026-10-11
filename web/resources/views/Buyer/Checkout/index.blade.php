@extends('Buyer.Layouts.app')

@section('title', 'Checkout')

@section('content')
    <div class="min-h-screen bg-blush py-8">
        <div class="mx-auto max-w-[1360px] px-4 sm:px-8">
            {{-- Page heading --}}
            <div class="mb-6">
                <h1 class="font-serif text-[32px] font-semibold text-buyer-ink sm:text-[38px]">Checkout</h1>
                <p class="mt-1 text-[13px] text-neutral-400">Enter shipping details and confirm your order.</p>
            </div>

            @if ($summary->isEmpty())
                {{-- Empty checkout --}}
                @include('Buyer.Cart._empty')
            @else
                <form method="POST" action="{{ route('buyer.checkout.store') }}" id="checkout-form">
                    @csrf
                    @isset($checkoutToken)
                        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
                    @endisset

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_420px]">
                        {{-- Left column: address + payment --}}
                        <div class="flex min-w-0 flex-col gap-4">
                            {{-- Shipping address --}}
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[18px] font-semibold text-buyer-ink mb-4">Shipping Address</h2>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label for="shipping_name" class="block text-[12.5px] font-medium text-buyer-ink mb-1">Full Name <span class="text-primary-700">*</span></label>
                                        <input type="text" id="shipping_name" name="shipping[name]" required
                                            value="{{ old('shipping.name', $buyer->fullName() ?? '') }}"
                                            class="w-full rounded-lg border border-buyer-line bg-white px-4 py-2.5 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800">
                                    </div>

                                    <div>
                                        <label for="shipping_phone" class="block text-[12.5px] font-medium text-buyer-ink mb-1">Phone <span class="text-primary-700">*</span></label>
                                        @php
                                            $normalizedPhone = '';
                                            if ($buyer->contact_no) {
                                                $normalized = preg_replace('/[\s\-()]/u', '', $buyer->contact_no);
                                                $normalizedPhone = $normalized;
                                            }
                                        @endphp
                                        <input type="tel" id="shipping_phone" name="shipping[phone]" required
                                            value="{{ old('shipping.phone', $normalizedPhone) }}"
                                            class="w-full rounded-lg border border-buyer-line bg-white px-4 py-2.5 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800">
                                    </div>

                                    <div>
                                        <label for="shipping_email" class="block text-[12.5px] font-medium text-buyer-ink mb-1">Email</label>
                                        <input type="email" id="shipping_email" name="shipping[email]"
                                            value="{{ old('shipping.email', $buyer->email ?? '') }}"
                                            class="w-full rounded-lg border border-buyer-line bg-white px-4 py-2.5 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800">
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label for="shipping_full" class="block text-[12.5px] font-medium text-buyer-ink mb-1">Full Address <span class="text-primary-700">*</span></label>
                                        @php
                                            $addressParts = array_filter([
                                                $buyer->house_number,
                                                $buyer->street,
                                                $buyer->barangay_name,
                                            ]);
                                            $prefillAddress = implode(', ', $addressParts);
                                            if ($buyer->address_detail) {
                                                $prefillAddress = ($prefillAddress ? $prefillAddress . ', ' : '') . $buyer->address_detail;
                                            }
                                        @endphp
                                        <textarea id="shipping_full" name="shipping[full]" rows="3" required
                                            class="w-full rounded-lg border border-buyer-line bg-white px-4 py-2.5 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800"
                                            placeholder="House/Building, Street, Barangay, City/Municipality, Province">{{ old('shipping.full', $prefillAddress) }}</textarea>
                                    </div>

                                    <div>
                                        <label for="shipping_municipality" class="block text-[12.5px] font-medium text-buyer-ink mb-1">Municipality / City <span class="text-primary-700">*</span></label>
                                        <input type="text" id="shipping_municipality" name="shipping[municipality]" required
                                            value="{{ old('shipping.municipality', $buyer->municipality_name ?? '') }}"
                                            class="w-full rounded-lg border border-buyer-line bg-white px-4 py-2.5 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800"
                                            placeholder="e.g., Quezon City">
                                    </div>

                                    <div>
                                        <label for="shipping_province" class="block text-[12.5px] font-medium text-buyer-ink mb-1">Province <span class="text-primary-700">*</span></label>
                                        <input type="text" id="shipping_province" name="shipping[province]" required
                                            value="{{ old('shipping.province', $buyer->province_name ?? '') }}"
                                            class="w-full rounded-lg border border-buyer-line bg-white px-4 py-2.5 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800"
                                            placeholder="e.g., Metro Manila">
                                    </div>

                                    <div>
                                        <label for="shipping_postal" class="block text-[12.5px] font-medium text-buyer-ink mb-1">Postal Code</label>
                                        <input type="text" id="shipping_postal" name="shipping[postal_code]"
                                            value="{{ old('shipping.postal_code', '') }}"
                                            class="w-full rounded-lg border border-buyer-line bg-white px-4 py-2.5 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800"
                                            placeholder="e.g., 1100">
                                    </div>
                                </div>
                            </section>

                            {{-- Payment method (COD only for now) --}}
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[18px] font-semibold text-buyer-ink mb-4">Payment Method</h2>

                                <div class="flex items-center gap-3 rounded-xl border border-buyer-line bg-white p-4">
                                    <input type="radio" id="payment_cod" name="payment_method" value="cod" checked
                                        class="h-4 w-4 cursor-pointer accent-primary-800">
                                    <div class="flex-1">
                                        <label for="payment_cod" class="block font-semibold text-buyer-ink">Cash on Delivery (COD)</label>
                                        <p class="text-[12.5px] text-neutral-400 mt-0.5">Pay when your order is delivered. No card needed.</p>
                                    </div>
                                </div>

                                <p class="mt-3 text-[12px] text-neutral-400">More payment methods coming soon.</p>
                            </section>

                            {{-- Place order button --}}
                            <button type="submit"
                                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-4 text-[14px] font-semibold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-40"
                                    @disabled($summary->subtotalCentavos === 0)>
                                <i data-lucide="lock" width="18" height="18"></i>
                                Place Order — {{ $summary->formattedTotal() }}
                            </button>

                            <p class="mt-3 text-center text-[11px] text-neutral-400">
                                By placing your order, you agree to our Terms of Service and Privacy Policy.
                            </p>
                        </div>

                        {{-- Right column: order summary (read-only) --}}
                        <aside class="lg:sticky lg:top-6 lg:h-fit">
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[20px] font-semibold text-buyer-ink mb-4">Order Summary</h2>

                                {{-- Seller groups (items) --}}
                                @foreach ($summary->sellerGroups as $group)
                                    <div class="border-b border-buyer-line pb-4 mb-4 last:border-0 last:pb-0 last:mb-0">
                                        <h3 class="font-semibold text-buyer-ink mb-2">{{ $group['seller']->business_name }}</h3>
                                        @foreach ($group['items'] as $item)
                                            <div class="flex items-center justify-between py-2 text-[13px]">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    @php
                                                        $imageUrl = $item->product?->catalogImageUrl();
                                                    @endphp
                                                    @if ($imageUrl)
                                                        <img src="{{ $imageUrl }}"
                                                             alt="{{ $item->product_name }}" class="h-12 w-12 rounded object-cover shrink-0">
                                                    @else
                                                        <div class="h-12 w-12 rounded bg-secondary-100 flex items-center justify-center shrink-0">
                                                            <i data-lucide="package" width="16" height="16" class="text-neutral-400"></i>
                                                        </div>
                                                    @endif
                                                    <div class="min-w-0">
                                                        <p class="font-medium text-buyer-ink truncate">{{ $item->product_name }}</p>
                                                        <p class="text-[11.5px] text-neutral-400">Qty: {{ $item->quantity }}</p>
                                                    </div>
                                                </div>
                                                <span class="font-semibold text-buyer-ink shrink-0">{{ $item->formattedLineTotal() }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach

                                {{-- Unavailable items notice --}}
                                @if ($summary->unavailableItems->isNotEmpty())
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 mb-4">
                                        <p class="text-[12.5px] font-medium text-amber-800">Some items in your cart are no longer available and have been excluded from this order.</p>
                                    </div>
                                @endif

                                {{-- Totals --}}
                                <div class="flex flex-col gap-2.5 border-t border-buyer-line pt-4">
                                    <div class="flex items-center justify-between text-[12.5px]">
                                        <span class="text-neutral-400">Subtotal</span>
                                        <span class="font-serif text-[14px] font-semibold text-buyer-ink">{{ $summary->formattedSubtotal() }}</span>
                                    </div>

                                    @if ($voucher)
                                        <div class="flex items-center justify-between text-[12.5px] text-tertiary-700">
                                            <span>Voucher Discount</span>
                                            <span class="font-semibold">{{ $summary->formattedDiscount() }}</span>
                                        </div>
                                    @endif

                                    <div class="flex items-center justify-between text-[12.5px]">
                                        <span class="text-neutral-400">Shipping</span>
                                        <span class="text-neutral-400">Calculated at checkout</span>
                                    </div>

                                    <div class="flex items-end justify-between border-t border-buyer-line pt-4 mt-2">
                                        <span class="text-[13px] font-semibold text-buyer-ink">Total</span>
                                        <span class="font-serif text-[26px] font-semibold text-buyer-ink">{{ $summary->formattedTotal() }}</span>
                                    </div>
                                </div>

                                <p class="mt-4 flex items-center justify-center gap-1.5 text-[11px] text-neutral-400">
                                    <i data-lucide="lock" width="12" height="12"></i>
                                    Secure 256-bit SSL encrypted checkout
                                </p>
                            </section>
                        </aside>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
@endpush