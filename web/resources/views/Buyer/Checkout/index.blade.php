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

            @if (session('error'))
                <div role="alert" class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-[13px] text-rose-900">
                    {{ session('error') }}
                </div>
            @endif

            @if (! empty($voucherNotice))
                <div role="status" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-900">
                    {{ $voucherNotice }}
                </div>
            @endif

            @if ($errors->any())
                <div role="alert" class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-[13px] text-rose-900">
                    <ul class="list-disc pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($summary->isEmpty())
                {{-- Empty checkout --}}
                @include('Buyer.Cart._empty')
            @else
                <form method="POST" action="{{ route('buyer.checkout.store') }}" id="checkout-form"
                      data-checkout-carriers
                      data-merchandise-minor="{{ $merchandiseTotalMinor ?? $summary->totalCentavos }}"
                      data-shipping-voucher-type="{{ $shippingVoucher?->type->value }}"
                      data-shipping-voucher-value="{{ $shippingVoucher?->value }}">
                    @csrf
                    @isset($checkoutToken)
                        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
                        <input type="hidden" name="price_fingerprint" value="{{ $summary->priceFingerprint() }}">
                        <input type="hidden" name="shipping_fingerprint" value="{{ $shippingFingerprint }}">
                        <input type="hidden" name="voucher_fingerprint" value="{{ $voucherFingerprint }}">
                        <input type="hidden" name="address_id" value="{{ $selectedAddress?->id }}">
                        <input type="hidden" name="address_fingerprint" value="{{ $addressFingerprint }}">
                    @endisset

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_420px]">
                        {{-- Left column: address + payment --}}
                        <div class="flex min-w-0 flex-col gap-4">
                            {{-- Shipping address --}}
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[18px] font-semibold text-buyer-ink mb-4">Shipping Address</h2>

                                @php
                                    $confirmParts = array_filter([
                                        $deliverTo->house_number,
                                        $deliverTo->street,
                                        $deliverTo->barangay_name,
                                        $deliverTo->municipality_name,
                                        $deliverTo->province_name,
                                        $deliverTo->postal_code,
                                    ]);
                                    $confirmFull = implode(', ', $confirmParts);
                                    if ($deliverTo->address_detail) {
                                        $confirmFull = ($confirmFull ? $confirmFull . ', ' : '') . $deliverTo->address_detail;
                                    }
                                    $addressComplete = filled($deliverTo->municipality_name) && filled($deliverTo->street);
                                @endphp

                                @unless ($addressComplete)
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 mb-4">
                                        <p class="text-[12.5px] font-medium text-amber-800">
                                            Your delivery address is incomplete. Add a municipality/city and street so a rider can be assigned.
                                        </p>
                                        <a href="{{ route('buyer.account.address') }}" class="mt-1 inline-block text-[12.5px] font-semibold text-primary-800 underline">Update my address</a>
                                    </div>
                                @endunless

                                <div class="rounded-xl border border-buyer-line bg-secondary-50/40 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-[13.5px] font-semibold text-buyer-ink">{{ $buyer->fullName() }}</p>
                                            <p class="mt-0.5 text-[12.5px] text-neutral-500">{{ $buyer->contact_no }}</p>
                                            <p class="mt-2 text-[13px] text-buyer-ink">{{ $confirmFull ?: '— no address on file —' }}</p>
                                        </div>
                                        <a href="{{ route('buyer.account.address') }}" class="shrink-0 text-[12.5px] font-semibold text-primary-800 underline">Manage addresses</a>
                                    </div>
                                    <p class="mt-3 text-[11.5px] text-neutral-400">
                                        Deliver to this saved address. Your order is frozen to this address once placed.
                                    </p>
                                </div>

                                {{-- Each choice reloads the page so fees and coverage are re-quoted by the server. --}}
                                @if ($addresses->count() > 1)
                                    <div class="mt-4" data-checkout-address-picker>
                                        <p class="mb-2 text-[12.5px] font-semibold text-buyer-ink">Deliver somewhere else</p>
                                        <ul class="flex flex-col gap-2">
                                            @foreach ($addresses as $option)
                                                @php
                                                    $isSelected = $option->is($selectedAddress);
                                                @endphp
                                                <li>
                                                    <a href="{{ route('buyer.checkout.index', ['items' => $checkoutItemIds, 'address' => $option->id]) }}"
                                                       @if ($isSelected) aria-current="true" @endif
                                                       class="block rounded-xl border px-4 py-3 text-[12.5px] {{ $isSelected ? 'border-primary-800 bg-secondary-50/40 font-semibold text-buyer-ink' : 'border-buyer-line text-neutral-500 hover:border-primary-800' }}">
                                                        {{ $option->summary() }}
                                                        @if ($option->is_default)
                                                            <span class="ml-1 text-[11.5px] font-normal text-neutral-400">(Default)</span>
                                                        @endif
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </section>

                            {{-- Shipping carriers (one selection per seller group) --}}
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[18px] font-semibold text-buyer-ink mb-1">Shipping</h2>
                                <p class="mb-4 text-[12.5px] text-neutral-400">Choose one carrier for each shop. Fees are calculated for your delivery address.</p>

                                <p data-checkout-shipping-block class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[12.5px] text-amber-900" @if($carriersAvailable ?? false) hidden @endif>
                                    Select an available carrier for every shop, or update your delivery address, before placing the order.
                                </p>

                                <div class="flex flex-col gap-4">
                                    @foreach (($carrierOptions ?? []) as $carrierGroup)
                                        <div class="rounded-xl border border-buyer-line p-4"
                                             data-carrier-group
                                             data-seller-id="{{ $carrierGroup['seller_id'] }}"
                                             data-available="{{ $carrierGroup['available'] ? '1' : '0' }}">
                                            <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <p class="text-[13.5px] font-semibold text-buyer-ink">{{ $carrierGroup['seller_name'] }}</p>
                                                    <p class="mt-0.5 text-[11.5px] text-neutral-400">
                                                        @if ($carrierGroup['route'] === 'same_province')
                                                            Same-province delivery
                                                        @elseif ($carrierGroup['route'] === 'cross_province')
                                                            Cross-province delivery
                                                        @else
                                                            Address incomplete
                                                        @endif
                                                        · Charged for {{ $carrierGroup['chargeable_kg'] }} kg
                                                    </p>
                                                </div>
                                            </div>

                                            @if (! $carrierGroup['available'])
                                                <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12.5px] text-rose-900">
                                                    {{ $carrierGroup['message'] }}
                                                </div>
                                            @else
                                                <div class="flex flex-col gap-2">
                                                    @foreach ($carrierGroup['carriers'] as $index => $carrier)
                                                        @php
                                                            $inputId = 'carrier_'.$carrierGroup['seller_id'].'_'.$carrier['id'];
                                                            $oldKey = 'carriers.'.$carrierGroup['seller_id'];
                                                            $oldValue = old($oldKey);
                                                            // A malformed (non-scalar) selection must not break rendering.
                                                            $oldValue = is_scalar($oldValue) ? (string) $oldValue : '';
                                                            $isChecked = $oldValue !== ''
                                                                ? $oldValue === (string) $carrier['id']
                                                                : $index === 0;
                                                        @endphp
                                                        <label for="{{ $inputId }}"
                                                               class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-buyer-line bg-white px-3 py-2.5">
                                                            <span class="flex min-w-0 items-center gap-2.5">
                                                                <input type="radio"
                                                                       id="{{ $inputId }}"
                                                                       name="carriers[{{ $carrierGroup['seller_id'] }}]"
                                                                       value="{{ $carrier['id'] }}"
                                                                       data-fee-minor="{{ $carrier['fee_minor'] }}"
                                                                       @checked($isChecked)
                                                                       class="h-4 w-4 shrink-0 accent-primary-800">
                                                                <span class="truncate text-[13px] font-medium text-buyer-ink">{{ $carrier['name'] }}</span>
                                                                 @if (! empty($carrier['destination_name']))
                                                                     <span class="ml-1 truncate text-[10.5px] text-neutral-400">via {{ $carrier['destination_name'] }}</span>
                                                                 @endif
                                                            </span>
                                                            <span class="shrink-0 text-[12.5px] font-semibold text-buyer-ink">{{ $carrier['fee_formatted'] }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @php
                                                $noteValue = old('notes.'.$carrierGroup['seller_id']);
                                            @endphp
                                            <label for="note_{{ $carrierGroup['seller_id'] }}" class="mt-3 block text-[12px] font-semibold text-buyer-ink">Note to seller (optional)</label>
                                            <textarea id="note_{{ $carrierGroup['seller_id'] }}"
                                                      name="notes[{{ $carrierGroup['seller_id'] }}]"
                                                      rows="2"
                                                      maxlength="{{ \App\Http\Controllers\Buyer\CheckoutController::NOTE_MAX_LENGTH }}"
                                                      placeholder="Up to {{ \App\Http\Controllers\Buyer\CheckoutController::NOTE_MAX_LENGTH }} characters"
                                                      class="mt-1 w-full rounded-lg border border-buyer-line px-3 py-2 text-[13px] text-buyer-ink">{{ is_string($noteValue) ? $noteValue : '' }}</textarea>
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            {{-- Payment method --}}
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[18px] font-semibold text-buyer-ink mb-4">Payment Method</h2>

                                <div class="flex flex-col gap-3">
                                        <label for="payment_cod"
                                               class="flex cursor-pointer items-start gap-3 rounded-xl border border-buyer-line bg-white p-4">
                                            <input type="radio" id="payment_cod" name="payment_method"
                                                value="cod" checked
                                                class="mt-0.5 h-4 w-4 cursor-pointer accent-primary-800">
                                            <div class="flex-1">
                                                <span class="block font-semibold text-buyer-ink">Cash on Delivery (COD)</span>
                                                <p class="text-[12.5px] text-neutral-400 mt-0.5">Pay cash when your order is delivered.</p>
                                            </div>
                                        </label>
                                </div>
                            </section>

                            {{-- Place order button --}}
                            <button type="submit"
                                    data-checkout-place-order
                                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-4 text-[14px] font-semibold text-white transition-opacity enabled:cursor-pointer enabled:hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800 disabled:cursor-not-allowed disabled:opacity-40"
                                    @disabled($summary->subtotalCentavos === 0 || ! ($carriersAvailable ?? false))>
                                <i data-lucide="shopping-bag" width="18" height="18" aria-hidden="true"></i>
                                <span data-checkout-place-label class="text-white">Place Order — ₱{{ number_format(($payableTotalMinor ?? $summary->totalCentavos) / 100, 2) }}</span>
                            </button>

                            <p class="mt-3 text-center text-[11px] text-neutral-400">
                                By placing your order, you agree to our Terms of Service and Privacy Policy.
                            </p>
                        </div>

                        {{-- Right column: order summary (read-only) --}}
                        <aside class="lg:sticky lg:top-6 lg:h-fit">
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[20px] font-semibold text-buyer-ink mb-4">Order Summary <span class="text-[13px]">{{ $summary->selectedLineCount() }} {{ Str::plural('item', $summary->selectedLineCount()) }}</span></h2>

                                {{-- Seller groups (items) --}}
                                @foreach ($summary->sellerGroups as $group)
                                    <div class="border-b border-buyer-line pb-4 mb-4 last:border-0 last:pb-0 last:mb-0">
                                        <h3 class="font-semibold text-buyer-ink mb-2">{{ $group['seller']->business_name }}</h3>
                                        @foreach ($group['items'] as $item)
                                            <div data-cart-item-id="{{ $item->id }}" class="flex items-center justify-between py-2 text-[13px]">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    @php
                                                        $imageUrl = $item->product?->catalogImageUrl();
                                                    @endphp
                                                    @if ($imageUrl)
                                                        <img src="{{ $imageUrl }}"
                                                             alt="{{ $item->product->name }}" class="h-12 w-12 rounded object-cover shrink-0">
                                                    @else
                                                        <div class="h-12 w-12 rounded bg-secondary-100 flex items-center justify-center shrink-0">
                                                            <i data-lucide="package" width="16" height="16" class="text-neutral-400"></i>
                                                        </div>
                                                    @endif
                                                    <div class="min-w-0">
                                                        <p class="font-medium text-buyer-ink truncate">{{ $item->product->name }}</p>
                                                        @if ($variantLabels[$item->id] !== '')
                                                            <p class="text-[11.5px] text-neutral-400">{{ $variantLabels[$item->id] }}</p>
                                                        @endif
                                                        <p class="text-[11.5px] text-neutral-400">Qty: {{ $item->quantity }}</p>
                                                        <p class="text-[11.5px] text-neutral-400">Unit Price: ₱{{ number_format($item->unitPriceCentavos() / 100, 2) }}@if ($regularPrice = $item->resolvedPrice()?->formattedRegular()) <span class="line-through">{{ $regularPrice }}</span>@endif</p>
                                                    </div>
                                                </div>
                                                <span class="font-semibold text-buyer-ink shrink-0">Subtotal: {{ $item->formattedLineTotal() }}</span>
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

                                    @foreach (($vouchers ?? []) as $applied)
                                        @if ($applied['scope'] !== 'shipping')
                                            <div class="flex items-center justify-between gap-3 text-[12.5px] text-tertiary-700">
                                                <span class="min-w-0 truncate">{{ $applied['code'] }} <span class="text-[10.5px] text-neutral-400">({{ $applied['scope'] }})</span></span>
                                                <span class="shrink-0 font-semibold">{{ $applied['discount_minor'] > 0 ? $applied['discount_formatted'] : 'Applied' }}</span>
                                            </div>
                                        @endif
                                    @endforeach

                                    <div class="flex items-center justify-between text-[12.5px]">
                                        <span class="text-neutral-400">Shipping</span>
                                        <span class="font-semibold text-buyer-ink" data-checkout-shipping-total>
                                            @if ($shippingTotalMinor ?? 0)
                                                ₱{{ number_format((($shippingTotalMinor ?? 0) - ($shippingDiscountMinor ?? 0)) / 100, 2) }}
                                            @else
                                                Select a carrier
                                            @endif
                                        </span>
                                    </div>

                                    @if ($shippingVoucher)
                                        <div class="flex items-center justify-between gap-3 text-[12.5px] text-tertiary-700"
                                             data-checkout-shipping-discount-row @if (($shippingDiscountMinor ?? 0) <= 0) hidden @endif>
                                            <span class="min-w-0 truncate">Shipping voucher ({{ $shippingVoucher->code }})</span>
                                            <span class="shrink-0 font-semibold" data-checkout-shipping-discount>-₱{{ number_format(($shippingDiscountMinor ?? 0) / 100, 2) }}</span>
                                        </div>
                                    @endif

                                    <div class="flex items-end justify-between border-t border-buyer-line pt-4 mt-2">
                                        <span class="text-[13px] font-semibold text-buyer-ink">Total</span>
                                        <span class="font-serif text-[26px] font-semibold text-buyer-ink" data-checkout-payable-total>
                                            ₱{{ number_format(($payableTotalMinor ?? $summary->totalCentavos) / 100, 2) }}
                                        </span>
                                    </div>
                                    <p class="mt-2 text-[11px] text-neutral-400">Merchandise total is charged at place-order today. Carrier assignment is saved for fulfilment in the next release.</p>
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
    @vite(['resources/js/buyer/checkout-carriers.js'])
@endpush
