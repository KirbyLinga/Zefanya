@extends('Layouts.seller')

@section('title', 'Order #{{ $sellerOrder->id }}')

@use('App\Enums\OrderStatus')

@push('scripts')
<script>
    function confirmAccept(btn) {
        if (confirm('Accept this order? You will start preparing it for shipment.')) {
            btn.closest('form').submit();
        }
    }
    function confirmMarkReady(btn) {
        if (confirm('Mark this order as packed and ready for pickup?')) {
            btn.closest('form').submit();
        }
    }

    // Message buyer button handler
    document.querySelectorAll('.message-buyer-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const orderId = btn.dataset.orderId;

            try {
                const response = await fetch('{{ route("seller.messages.store") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        order_id: orderId,
                    }),
                });

                if (response.ok) {
                    // Navigate to messages page to show the conversation
                    window.location.href = '{{ route("seller.messages.index") }}';
                } else {
                    alert('Failed to open conversation');
                }
            } catch (error) {
                console.error('Error opening conversation:', error);
                alert('Error opening conversation');
            }
        });
    });
</script>
@endpush

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Order #{{ $sellerOrder->id }}</h1>
    <a href="{{ route('seller.orders.index') }}" class="font-sans text-[12.5px] font-semibold text-[var(--link)]">← Back to orders</a>
</div>

<div class="grid grid-cols-[2fr_1fr] gap-6 max-xl:grid-cols-1">
    {{-- ── Left: items + timeline ────────────────────────────────── --}}
    <div class="space-y-6">
        {{-- Buyer / shipping --}}
        @php($order = $sellerOrder->order)
        @php($buyer = $order?->buyer)
        <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Buyer &amp; shipping</h2>
            <p class="font-sans text-[13.5px] font-semibold text-[var(--text-ink)]">{{ $buyer?->fullName() ?? '—' }}</p>
            @php($addr = $order?->shipping_address ?? [])
            <p class="mt-1 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $addr['full'] ?? '—' }}</p>
            @isset($addr['phone'])
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $addr['phone'] }}</p>
            @endisset
        </section>

        {{-- Line items --}}
        <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Items</h2>
            <ul class="divide-y divide-[var(--border-strong)]">
                @foreach($sellerOrder->orderItems as $item)
                    <li class="flex items-center justify-between py-2.5">
                        <div>
                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $item->product_name }}</p>
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)]">QTY &times; {{ $item->quantity }}</p>
                        </div>
                        <span class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $item->formattedLineTotal() }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="flex justify-end border-t border-[var(--border-strong)] mt-3 pt-3">
                <div class="text-right">
                    <p class="flex justify-between gap-4 font-sans text-[12.5px] text-[var(--text-muted)]">
                        <span>Subtotal</span><span>{{ $sellerOrder->formattedSubtotal() }}</span>
                    </p>
                </div>
            </div>
        </section>

        {{-- Status timeline --}}
        <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Status timeline</h2>
            @if($sellerOrder->statusHistories->isEmpty())
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
                    @foreach($sellerOrder->statusHistories as $hist)
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
    </div>

    {{-- ── Right: actions + tracking ─────────────────────────────── --}}
    <div class="space-y-6">
        {{-- Current status --}}
        <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Current status</h2>
            <p class="font-sans text-[20px] font-bold text-[var(--text-ink)]">{{ $sellerOrder->status->label() }}</p>
            @if($sellerOrder->tracking_number)
                <p class="mt-2 font-mono text-[12.5px] text-[var(--text-ink)] break-all">{{ $sellerOrder->tracking_number }}</p>
                <a href="{{ route('seller.shipments.label', $sellerOrder) }}" target="_blank"
                   class="mt-2 inline-flex items-center h-8 px-3 rounded-sm border border-[var(--border-strong)] font-sans text-[11.5px] font-semibold text-[var(--text-ink)] hover:bg-black/5">
                    Print waybill
                </a>
            @endif
            @if($sellerOrder->handover_at)
                <p class="mt-3 pt-3 border-t border-[var(--border-strong)] font-sans text-[11.5px] text-[var(--text-muted)]">
                    Handed to rider {{ $sellerOrder->handover_at->format('M d, Y h:i A') }}
                    @if($sellerOrder->courier) · {{ $sellerOrder->courier->name }}@endif
                </p>
            @endif
        </section>

        {{-- Courier --}}
        <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Courier</h2>
            @if($sellerOrder->courier)
                <p class="font-sans text-[13.5px] font-semibold text-[var(--text-ink)]">{{ $sellerOrder->courier->name }}</p>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $sellerOrder->courier->phone }}</p>
                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ ucfirst($sellerOrder->courier->vehicle_type) }}</p>
                <p class="font-sans text-[10.5px] text-[var(--text-muted)] mt-1">Pickup courier</p>
            @else
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                    @if(in_array($sellerOrder->status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing]))
                        A courier will be assigned when a rider picks up this parcel.
                    @elseif($sellerOrder->status === OrderStatus::ReadyForPickup)
                        Waiting for a rider to pick up this parcel.
                    @else
                        No pickup courier assigned yet.
                    @endif
                </p>
            @endif
            @if($sellerOrder->deliveryCourier)
                <div class="mt-3 pt-3 border-t border-[var(--border-strong)]">
                    <p class="font-sans text-[13.5px] font-semibold text-[var(--text-ink)]">{{ $sellerOrder->deliveryCourier->name }}</p>
                    <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $sellerOrder->deliveryCourier->phone }}</p>
                    <p class="font-sans text-[10.5px] text-[var(--text-muted)]">Delivery courier</p>
                </div>
            @endif
        </section>

        {{-- Actions --}}
        <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Actions</h2>
            <div class="flex flex-col gap-2">
                @if($sellerOrder->status === OrderStatus::Placed)
                    <form method="POST" action="{{ route('seller.orders.accept', $sellerOrder) }}" class="w-full">
                        @csrf
                        <button type="button"
                            class="w-full inline-flex items-center justify-center h-9 px-3 rounded-sm font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                            onclick="confirmAccept(this)">
                            Accept order
                        </button>
                    </form>
                @elseif(in_array($sellerOrder->status, [OrderStatus::Confirmed, OrderStatus::Preparing]))
                    <form method="POST" action="{{ route('seller.orders.markReady', $sellerOrder) }}" class="w-full">
                        @csrf
                        <button type="button"
                            class="w-full inline-flex items-center justify-center h-9 px-3 rounded-sm font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                            onclick="confirmMarkReady(this)">
                            Mark as ready for pickup
                        </button>
                    </form>
                @else
                    <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                        @if($sellerOrder->status === OrderStatus::ReadyForPickup)
                            Waiting for a rider to pick up this parcel.
                        @elseif(in_array($sellerOrder->status, [OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery]))
                            In transit to delivery.
                        @else
                            No further action available from this step.
                        @endif
                    </p>
                @endif

                {{-- Message buyer button --}}
                @if ($buyer)
                    <button
                        type="button"
                        class="message-buyer-btn w-full inline-flex items-center justify-center h-9 px-3 rounded-sm font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-primary-700 border border-primary-300 bg-primary-50 hover:bg-primary-100"
                        data-buyer-id="{{ $buyer->id }}"
                        data-order-id="{{ $order->id }}"
                    >
                        💬 Message buyer
                    </button>
                @endif
            </div>

        {{-- Payment --}}
        @if($sellerOrder->payment)
            <section class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-5">
                <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Payment</h2>
                <p class="font-sans text-[13.5px] text-[var(--text-ink)]">{{ $sellerOrder->payment->formattedAmount() }} (COD)</p>
                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Status: {{ $sellerOrder->payment->status->value }}</p>
            </section>
        @endif
    </div>
</div>
@endsection
