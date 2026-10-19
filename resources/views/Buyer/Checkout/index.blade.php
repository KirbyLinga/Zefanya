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

                                @php
                                    $confirmParts = array_filter([
                                        $buyer->house_number,
                                        $buyer->street,
                                        $buyer->barangay_name,
                                        $buyer->municipality_name,
                                        $buyer->province_name,
                                        $buyer->postal_code,
                                    ]);
                                    $confirmFull = implode(', ', $confirmParts);
                                    if ($buyer->address_detail) {
                                        $confirmFull = ($confirmFull ? $confirmFull . ', ' : '') . $buyer->address_detail;
                                    }
                                    $addressComplete = filled($buyer->municipality_name) && filled($buyer->street);
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
                                        <a href="{{ route('buyer.account.address') }}" class="shrink-0 text-[12.5px] font-semibold text-primary-800 underline">Change</a>
                                    </div>
                                    <p class="mt-3 text-[11.5px] text-neutral-400">
                                        Deliver to this saved address. Your order is frozen to this address once placed.
                                    </p>
                                </div>
                            </section>

                            {{-- Payment method --}}
                            <section class="rounded-2xl border border-buyer-line bg-white p-5">
                                <h2 class="font-serif text-[18px] font-semibold text-buyer-ink mb-4">Payment Method</h2>

                                @php $paymentMethods = \App\Enums\PaymentMethod::cases(); @endphp

                                <div class="flex flex-col gap-3">
                                    @foreach ($paymentMethods as $method)
                                        <label for="payment_{{ $method->value }}"
                                               class="flex cursor-pointer items-start gap-3 rounded-xl border border-buyer-line bg-white p-4">
                                            <input type="radio" id="payment_{{ $method->value }}" name="payment_method"
                                                value="{{ $method->value }}" @checked(old('payment_method', 'cod') === $method->value)
                                                class="mt-0.5 h-4 w-4 cursor-pointer accent-primary-800">
                                            <div class="flex-1">
                                                <span class="block font-semibold text-buyer-ink">{{ $method->label() }}</span>
                                                @if ($method->isIntegrated())
                                                    <p class="text-[12.5px] text-neutral-400 mt-0.5">Pay cash when your order is delivered. No card needed.</p>
                                                @else
                                                    <p class="text-[12.5px] text-amber-700 mt-0.5">Recorded as <span class="font-semibold">pending</span> — this method is not yet integrated. A representative will follow up to collect payment.</p>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
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
                                                        <p class="text-[11.5px] text-neutral-400">Unit Price: ₱{{ number_format($item->unitPriceCentavos() / 100, 2) }}</p>
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