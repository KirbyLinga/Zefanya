@extends('Buyer.Layouts.app')

@section('title', 'Order Confirmed')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-10">
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center">
            <p class="text-sm font-semibold tracking-wide text-emerald-700 uppercase">Order placed</p>
            <h1 class="mt-2 font-serif text-3xl text-[#0a0a0a]">Thank you, {{ $order->buyer->fullName() }}!</h1>
            <p class="mt-2 text-sm text-neutral-600">
                Your order <span class="font-mono font-semibold text-[#0a0a0a]">{{ $order->reference }}</span>
                has been placed and is awaiting seller confirmation.
            </p>
            <p class="mt-1 text-xs text-neutral-500">Status: {{ $order->status->value }}</p>
        </div>

        @if (session('voucher_notice'))
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ session('voucher_notice') }}
            </div>
        @endif

        @foreach ($order->sellerOrders as $sellerOrder)
            <div class="mt-6 rounded-2xl border border-neutral-200 bg-white p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-[#0a0a0a]">{{ $sellerOrder->seller->business_name }}</h2>
                    <span class="text-xs tracking-wide text-neutral-500 uppercase">COD &middot; pay on delivery</span>
                </div>

                <ul class="mt-4 divide-y divide-neutral-100">
                    @foreach ($sellerOrder->orderItems as $item)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <span class="text-neutral-700">
                                {{ $item->product_name }}
                                <span class="text-neutral-400">&times;{{ $item->quantity }}</span>
                            </span>
                            <span class="font-medium text-[#0a0a0a]">{{ $item->formattedLineTotal() }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3 flex items-center justify-between border-t border-neutral-100 pt-3 text-sm">
                    <span class="text-neutral-500">Subtotal</span>
                    <span class="font-medium text-[#0a0a0a]">{{ $sellerOrder->formattedSubtotal() }}</span>
                </div>
                @if ($sellerOrder->payment)
                    <div class="mt-1 flex items-center justify-between text-sm">
                        <span class="text-neutral-500">Collect on delivery</span>
                        <span class="font-semibold text-[#0a0a0a]">{{ $sellerOrder->payment->formattedAmount() }}</span>
                    </div>
                @endif
            </div>
        @endforeach

        <div class="mt-6 rounded-2xl border border-neutral-200 bg-white p-5">
            <div class="flex items-center justify-between text-sm">
                <span class="text-neutral-500">Subtotal</span>
                <span class="text-neutral-700">{{ $order->formattedSubtotal() }}</span>
            </div>
            @if ($order->discount_minor > 0)
                <div class="mt-1 flex items-center justify-between text-sm">
                    <span class="text-neutral-500">Voucher discount</span>
                    <span class="text-emerald-700">{{ $order->formattedDiscount() }}</span>
                </div>
            @endif
            <div class="mt-3 flex items-center justify-between border-t border-neutral-100 pt-3">
                <span class="font-semibold text-[#0a0a0a]">Total</span>
                <span class="text-lg font-bold text-[#0a0a0a]">{{ $order->formattedTotal() }}</span>
            </div>

            <div class="mt-4 rounded-lg bg-neutral-50 px-4 py-3 text-xs text-neutral-500">
                Deliver to: {{ $order->shipping_address['name'] ?? '' }} &middot;
                {{ $order->shipping_address['full'] ?? '' }}
                @isset($order->shipping_address['phone'])
                    &middot; {{ $order->shipping_address['phone'] }}
                @endisset
            </div>
        </div>

        <div class="mt-6 flex justify-center gap-3">
            <a href="{{ route('buyer.home') }}"
               class="rounded-full border border-neutral-300 px-5 py-2.5 text-sm font-semibold text-[#0a0a0a] hover:bg-neutral-50">
                Continue shopping
            </a>
            <a href="{{ route('buyer.orders.index') }}"
               class="rounded-full bg-[#0a0a0a] px-5 py-2.5 text-sm font-semibold text-white hover:bg-neutral-800">
                View my orders
            </a>
        </div>
    </div>
@endsection
