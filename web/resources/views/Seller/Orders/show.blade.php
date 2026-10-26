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
    function confirmDecline(btn) {
        if (confirm('Decline this order? Stock will be restored and the buyer will be notified. Other sellers on the same checkout are not affected.')) {
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
            <x-shipment-tracking :shipment="$sellerOrder" />

                            @php($hubMovements = $sellerOrder->manifestMovementEvents())
            @if ($hubMovements !== [])
                <div class="mt-5 border-t border-[var(--border-strong)] pt-4">
                    <h3 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)]">Hub movements</h3>
                    <p class="mt-1 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">Internal transfers do not change the parcel's delivery status.</p>
                    <ul class="mt-3 space-y-3">
                        @foreach ($hubMovements as $movement)
                            <li class="flex items-start gap-2.5">
                                <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border {{ $movement['type'] === 'dispatch' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
                                    <i data-lucide="{{ $movement['type'] === 'dispatch' ? 'truck' : 'warehouse' }}" class="size-2.5"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">{{ $movement['title'] }}</p>
                                    <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $movement['at']->format('M d, Y h:i A') }} · {{ $movement['detail'] }}</p>
                                    @if (filled($movement['custody'] ?? null))
                                        <p class="mt-0.5 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">{{ $movement['custody'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
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
                </p>
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
                    <form method="POST" action="{{ route('seller.orders.decline', $sellerOrder) }}" class="w-full">
                        @csrf
                        <label class="sr-only" for="decline-reason-{{ $sellerOrder->id }}">Decline reason (optional)</label>
                        <input type="text" name="reason" id="decline-reason-{{ $sellerOrder->id }}" maxlength="500"
                            placeholder="Reason (optional)"
                            class="mb-2 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-sans text-[12.5px] text-[var(--text-ink)]">
                        <button type="button"
                            class="w-full inline-flex items-center justify-center h-9 px-3 rounded-sm font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-primary-800 border border-primary-800 bg-transparent hover:bg-primary-50"
                            onclick="confirmDecline(this)">
                            Decline order
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
                    <form method="POST" action="{{ route('seller.orders.decline', $sellerOrder) }}" class="w-full">
                        @csrf
                        <label class="sr-only" for="decline-reason-pack-{{ $sellerOrder->id }}">Decline reason (optional)</label>
                        <input type="text" name="reason" id="decline-reason-pack-{{ $sellerOrder->id }}" maxlength="500"
                            placeholder="Reason (optional)"
                            class="mb-2 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-sans text-[12.5px] text-[var(--text-ink)]">
                        <button type="button"
                            class="w-full inline-flex items-center justify-center h-9 px-3 rounded-sm font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-primary-800 border border-primary-800 bg-transparent hover:bg-primary-50"
                            onclick="confirmDecline(this)">
                            Decline order
                        </button>
                    </form>
                @else
                    <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                        @if($sellerOrder->status === OrderStatus::ReadyForPickup)
                            Waiting for a rider to pick up this parcel.
                        @elseif(in_array($sellerOrder->status, [OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery]))
                            In transit to delivery.
                        @elseif($sellerOrder->status === OrderStatus::Cancelled)
                            You declined this order. Stock was restored.
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
