@extends('Buyer.Layouts.app')

@section('title', 'Order Confirmed')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-10">
        <div class="rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-6 text-center">
            <p class="text-sm font-semibold tracking-wide text-emerald-700 uppercase">Order placed</p>
            <h1 class="mt-2 font-serif text-3xl text-[var(--text-ink)]">Thank you, {{ $order->buyer->fullName() }}!</h1>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
                Your order <span class="font-mono font-semibold text-[var(--text-ink)]">{{ $order->reference }}</span>
                has been placed. Follow each shipment in your order details.
            </p>
            <p class="mt-2 text-xs text-[var(--text-muted)]">Overall order: <span class="font-semibold text-[var(--text-ink)]">{{ $order->computeRollupStatus()?->buyerLabel() ?? 'Preparing' }}</span></p>
            @if ($order->sellerOrders->count() > 1)<p class="mt-2 text-xs leading-relaxed text-[var(--text-muted)]">Each shop sends a separate parcel. Your shipments can progress at different stages; track each one in your order details.</p>@endif
        </div>

        @if (session('voucher_notice'))
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ session('voucher_notice') }}
            </div>
        @endif

        @foreach ($order->sellerOrders as $sellerOrder)
            <div class="mt-6 rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-[var(--text-ink)]">{{ $sellerOrder->seller->business_name }}</h2>
                    <span class="text-xs tracking-wide text-[var(--text-muted)] uppercase">COD &middot; pay on delivery</span>
                </div>

                <ul class="mt-4 divide-y divide-neutral-100">
                    @foreach ($sellerOrder->orderItems as $item)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <span class="text-[var(--text-body)]">
                                {{ $item->product_name }}
                                @if ($item->variantDescription() !== '')
                                    ({{ $item->variantDescription() }})
                                @endif
                                <span class="text-[var(--text-muted)]">&times;{{ $item->quantity }}</span>
                            </span>
                            <span class="font-medium text-[var(--text-ink)]">{{ $item->formattedLineTotal() }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3 flex items-center justify-between border-t border-neutral-100 pt-3 text-sm">
                    <span class="text-[var(--text-muted)]">Subtotal</span>
                    <span class="font-medium text-[var(--text-ink)]">{{ $sellerOrder->formattedSubtotal() }}</span>
                </div>
                @if ($sellerOrder->payment)
                    <div class="mt-1 flex items-center justify-between text-sm">
                        <span class="text-[var(--text-muted)]">Collect on delivery</span>
                        <span class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->payment->formattedAmount() }}</span>
                    </div>
                @endif
            </div>
        @endforeach

        <div class="mt-6 rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
            <div class="flex items-center justify-between text-sm">
                <span class="text-[var(--text-muted)]">Subtotal</span>
                <span class="text-[var(--text-body)]">{{ $order->formattedSubtotal() }}</span>
            </div>
            @if ($order->discount_minor > 0)
                <div class="mt-1 flex items-center justify-between text-sm">
                    <span class="text-[var(--text-muted)]">Voucher discount</span>
                    <span class="text-emerald-700">{{ $order->formattedDiscount() }}</span>
                </div>
            @endif
            @php
                $shippingTotalMinor = (int) $order->sellerOrders->sum('shipping_fee_minor');
            @endphp
            @if ($shippingTotalMinor > 0)
                <div class="mt-1 flex items-center justify-between text-sm">
                    <span class="text-[var(--text-muted)]">Shipping</span>
                    <span class="text-[var(--text-body)]">₱{{ number_format($shippingTotalMinor / 100, 2) }}</span>
                </div>
            @endif
            @if ($order->shipping_discount_minor > 0)
                <div class="mt-1 flex items-center justify-between text-sm">
                    <span class="text-[var(--text-muted)]">Shipping voucher</span>
                    <span class="text-emerald-700">-₱{{ number_format($order->shipping_discount_minor / 100, 2) }}</span>
                </div>
            @endif
            <div class="mt-3 flex items-center justify-between border-t border-neutral-100 pt-3">
                <span class="font-semibold text-[var(--text-ink)]">Total</span>
                <span class="text-lg font-bold text-[var(--text-ink)]">{{ $order->formattedTotal() }}</span>
            </div>

            <div class="mt-4 rounded-lg bg-[var(--bg-page)] px-4 py-3 text-xs text-[var(--text-muted)]">
                Deliver to: {{ $order->shipping_address['name'] ?? '' }} &middot;
                {{ $order->shipping_address['full'] ?? '' }}
                @isset($order->shipping_address['phone'])
                    &middot; {{ $order->shipping_address['phone'] }}
                @endisset
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('buyer.home') }}"
               class="rounded-full border border-neutral-300 px-5 py-2.5 text-sm font-semibold text-[var(--text-ink)] hover:bg-neutral-50">
                Continue shopping
            </a>
            <a href="{{ route('buyer.orders.show', $order) }}"
               class="rounded-full bg-[#0a0a0a] px-5 py-2.5 text-sm font-semibold text-white hover:bg-neutral-800">
                Track this order
            </a>
            <a href="{{ route('buyer.orders.index') }}"
               class="rounded-full border border-neutral-300 px-5 py-2.5 text-sm font-semibold text-[var(--text-ink)] hover:bg-neutral-50">
                View all orders
            </a>
        </div>
    </div>
@endsection
