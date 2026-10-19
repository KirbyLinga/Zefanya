@extends('Buyer.Layouts.app')

@section('title')
    Order {{ $order->reference }}
@endsection

@use('App\Enums\OrderStatus')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Order {{ $order->reference }}</h1>
        <a href="{{ route('buyer.orders.index') }}" class="font-sans text-[12.5px] font-semibold text-[var(--link)]">← Back to orders</a>
    </div>

    <div class="grid grid-cols-[2fr_1fr] gap-6 max-xl:grid-cols-1">
        {{-- ── Left: items + timeline ────────────────────────────────── --}}
        <div class="space-y-6">
            {{-- Shipping address --}}
            @php($addr = $order->shipping_address ?? [])
            <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
                <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Shipping address</h2>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $addr['full'] ?? '—' }}</p>
                @isset($addr['phone'])
                    <p class="font-sans text-[12.5px] text-[var(--text-muted)] mt-1">{{ $addr['phone'] }}</p>
                @endisset
            </section>

            {{-- Line items across all seller_orders --}}
            <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
                <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Items</h2>
                <ul class="divide-y divide-[var(--border-strong)]">
                    @foreach ($order->sellerOrders as $sellerOrder)
                        @foreach ($sellerOrder->orderItems as $item)
                            <li class="flex items-center justify-between py-2.5">
                                <div>
                                    <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $item->product_name }}</p>
                                    <p class="font-sans text-[11.5px] text-[var(--text-muted)]">QTY × {{ $item->quantity }} · {{ $sellerOrder->seller?->name ?? '—' }}</p>
                                </div>
                                <span class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ '₱'.number_format($item->price_minor / 100 * $item->quantity, 2) }}</span>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
                <div class="flex justify-end border-t border-[var(--border-strong)] mt-3 pt-3">
                    <div class="text-right">
                        <p class="flex justify-between gap-4 font-sans text-[12.5px] text-[var(--text-muted)]">
                            <span>Subtotal</span><span>{{ $order->formattedSubtotal() }}</span>
                        </p>
                        @if ($order->discount_minor > 0)
                            <p class="flex justify-between gap-4 font-sans text-[12.5px] text-[var(--text-muted)] mt-1">
                                <span>Discount</span><span>{{ $order->formattedDiscount() }}</span>
                            </p>
                        @endif
                        <p class="flex justify-between gap-4 font-sans text-[14px] font-bold text-[var(--text-ink)] mt-2">
                            <span>Total</span><span>{{ $order->formattedTotal() }}</span>
                        </p>
                    </div>
                </div>
            </section>

            {{-- Status timeline per seller_order --}}
            @foreach ($order->sellerOrders as $sellerOrder)
                <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)]">
                            {{ $sellerOrder->seller?->name ?? 'Seller' }} — Status timeline
                        </h2>
                        @if ($sellerOrder->seller && Auth::guard('buyer')->check())
                            <button
                                type="button"
                                class="chat-with-seller-btn text-[11px] font-semibold text-primary-800 hover:text-primary-900 no-underline"
                                data-seller-id="{{ $sellerOrder->seller->id }}"
                                data-order-id="{{ $order->id }}"
                            >
                                💬 Chat
                            </button>
                        @endif
                    </div>
                    @if ($sellerOrder->statusHistories->isEmpty())
                        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                            Current status: <span class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->status->label() }}</span>
                        </p>
                    @else
                        <ul class="space-y-3">
                            <li class="flex items-start gap-2.5">
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)] mt-0.5">↳</span>
                                <div>
                                    <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Order created</p>
                                    <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $sellerOrder->created_at->format('M d, Y h:i A') }}</p>
                                </div>
                            </li>
                            @foreach ($sellerOrder->statusHistories as $hist)
                                <li class="flex items-start gap-2.5">
                                    <span class="font-sans text-[11.5px] text-[var(--text-muted)] mt-0.5">→</span>
                                    <div>
                                        <p class="font-sans text-[12.5px] text-[var(--text-ink)] font-semibold">
                                            {{ $hist->from_status?->label() ?? '—' }} → {{ $hist->to_status->label() }}
                                        </p>
                                        <p class="font-sans text-[11px] text-[var(--text-muted)]">
                                            {{ $hist->created_at->format('M d, Y h:i A') }}
                                            @isset($hist->changed_by) · <span class="font-semibold">{{ $hist->changed_by }}</span>@endisset
                                        </p>
                                        @isset($hist->note)
                                            <p class="font-sans text-[11.5px] text-[var(--text-muted)] italic">{{ $hist->note }}</p>
                                        @endisset
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3 font-sans text-[12.5px] text-[var(--text-muted)]">
                            Current: <span class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->status->label() }}</span>
                        </p>
                    @endif
                </section>
            @endforeach
        </div>

        {{-- ── Right: tracking + confirm ─────────────────────────────── --}}
        <div class="space-y-6">
            @foreach ($order->sellerOrders as $sellerOrder)
                @php($courier = $sellerOrder->courier)
                <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
                    <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">
                        {{ $sellerOrder->seller?->name ?? 'Seller' }} — Shipment
                    </h2>
                    <div class="space-y-3">
                        <div>
                            <p class="font-sans text-[11px] font-semibold text-[var(--text-muted)] mb-1">Status</p>
                            <p class="font-sans text-[14px] font-bold text-[var(--text-ink)]">{{ $sellerOrder->status->label() }}</p>
                        </div>
                        @if ($sellerOrder->tracking_number)
                            <div>
                                <p class="font-sans text-[11px] font-semibold text-[var(--text-muted)] mb-1">Tracking number</p>
                                <p class="font-mono text-[12.5px] text-[var(--text-ink)] break-all">{{ $sellerOrder->tracking_number }}</p>
                            </div>
                        @endif
                        @if ($courier)
                            <div>
                                <p class="font-sans text-[11px] font-semibold text-[var(--text-muted)] mb-1">Courier</p>
                                <p class="font-sans text-[13.5px] font-semibold text-[var(--text-ink)]">{{ $courier->name }}</p>
                                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $courier->phone }}</p>
                                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ ucfirst($courier->vehicle_type) }}</p>
                            </div>
                        @else
                            <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                                @if(in_array($sellerOrder->status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing]))
                                    A courier will be assigned once the seller marks this order ready for pickup.
                                @elseif($sellerOrder->status === OrderStatus::ReadyForPickup)
                                    Waiting for courier assignment.
                                @else
                                    No courier assigned yet.
                                @endif
                            </p>
                        @endif
                    </div>
                </section>
            @endforeach

            {{-- Confirm Received action --}}
            @if ($canConfirm)
                <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
                    <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Action required</h2>
                    <p class="font-sans text-[13px] text-[var(--text-ink)] mb-4">Items from one or more sellers have been delivered. Please confirm receipt to complete the order.</p>
                    <form method="POST" action="{{ route('buyer.orders.confirmReceived', $order) }}">
                        @csrf
                        <button type="submit"
                            class="w-full inline-flex items-center justify-center h-9 px-3 rounded-sm font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-white bg-emerald-600 hover:bg-emerald-700"
                            onclick="return confirm('Confirm that you have received the delivered items?')">
                            Confirm Received
                        </button>
                    </form>
                </section>
            @elseif ($order->sellerOrders->contains(fn ($so) => $so->status !== OrderStatus::Completed))
                <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
                    <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Some items are still in transit. "Confirm Received" will appear when they are delivered.</p>
                </section>
            @endif
        </div>
    </div>

    <script>
        document.querySelectorAll('.chat-with-seller-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const sellerId = btn.dataset.sellerId;
                const orderId = btn.dataset.orderId;

                try {
                    const response = await fetch('{{ route("buyer.messages.getOrCreate") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            seller_id: sellerId,
                            order_id: orderId,
                        }),
                    });

                    if (response.ok) {
                        const data = await response.json();
                        window.location.href = `/buyer/messages/${data.conversation_id}`;
                    } else {
                        alert('Failed to start conversation');
                    }
                } catch (error) {
                    console.error('Error starting conversation:', error);
                    alert('Error starting conversation');
                }
            });
        });
    </script>
@endsection