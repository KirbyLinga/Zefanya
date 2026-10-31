@extends('Layouts.seller')

@section('title', 'Order #'.$sellerOrder->id)

@use('App\Enums\OrderStatus')

@push('scripts')
<script>
    function confirmAccept(btn) {
        if (confirm('Accept this order? You can pack it in the next step.')) {
            btn.closest('form').submit();
        }
    }
    function confirmMarkReady(btn) {
        if (confirm('Mark this order as packed and ready for pickup?')) {
            btn.closest('form').submit();
        }
    }
    function confirmDecline(btn) {
        const form = btn.closest('form');
        if (form.reportValidity() && confirm('Decline this order? Stock and any eligible voucher will be returned and the buyer will be notified. Other sellers on the same checkout are not affected.')) {
            form.submit();
        }
    }
</script>
@endpush

@section('content')
<div class="w-full font-sans [&_button]:transition-colors [&_button]:focus-visible:outline-2 [&_button]:focus-visible:outline-offset-2 [&_button]:focus-visible:outline-primary-700 [&_input]:focus:border-primary-700 [&_input]:focus:outline-none [&_input]:focus:ring-2 [&_input]:focus:ring-primary-200">
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Order #{{ $sellerOrder->id }}</h1>
    <a href="{{ route('seller.orders.index') }}" class="inline-flex min-h-10 items-center rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 font-sans text-[12.5px] font-semibold text-[var(--link)] transition-colors hover:border-primary-700 hover:bg-primary-700 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">← Back to orders</a>
</div>

@if(session('error'))
    <p class="mb-4 rounded-sm bg-[#fee2e2] px-3 py-2 font-sans text-[12.5px] text-[#b91c1c]">{{ session('error') }}</p>
@elseif(session('declined'))
    <p class="mb-4 rounded-sm bg-[#dcfce7] px-3 py-2 font-sans text-[12.5px] text-[#15803d]">Order cancelled. Stock and any eligible voucher were returned and the buyer was notified.</p>
@endif

<div class="grid grid-cols-1 items-start gap-6 xl:gap-8 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
    {{-- ── Left: items + timeline ────────────────────────────────── --}}
    <div class="min-w-0 space-y-6">
        {{-- Buyer / shipping --}}
        @php($order = $sellerOrder->order)
        @php($buyer = $order?->buyer)
        <section class="border border-[var(--border-strong)] rounded-2xl bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6 2xl:p-8">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Buyer &amp; shipping</h2>
            @php($addr = $order?->shipping_address ?? [])
            <p class="font-sans text-[13.5px] font-semibold text-[var(--text-ink)]">{{ $addr['name'] ?? $buyer?->fullName() ?? '—' }}</p>
            <p class="mt-1 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $addr['full'] ?? '—' }}</p>
            @isset($addr['phone'])
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $addr['phone'] }}</p>
            @endisset
        </section>

        {{-- Line items --}}
        <section class="border border-[var(--border-strong)] rounded-2xl bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6 2xl:p-8">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Items</h2>
            <ul class="divide-y divide-[var(--border-strong)]">
                @foreach($sellerOrder->orderItems as $item)
                    <li class="flex items-start justify-between gap-4 py-4">
                        <div>
                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $item->product_name }}</p>
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Quantity: {{ $item->quantity }}</p>
                        </div>
                        <span class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $item->formattedLineTotal() }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="flex justify-end border-t border-[var(--border-strong)] mt-3 pt-3">
                <div class="text-right">
                    <p class="flex justify-between gap-4 font-sans text-[12.5px] text-[var(--text-muted)]">
                        <span class="text-inherit">Subtotal</span><span class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->formattedSubtotal() }}</span>
                    </p>
                </div>
            </div>
        </section>

        {{-- Status timeline --}}
        <section class="border border-[var(--border-strong)] rounded-2xl bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6 2xl:p-8">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Status timeline</h2>
                        <details class="group rounded-xl border border-[var(--border-strong)] bg-[var(--bg-page)] p-4">
                <summary class="flex min-h-10 cursor-pointer items-center justify-between gap-3 rounded-lg text-sm font-semibold text-[var(--link)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                    <span class="text-inherit">View shipment progress and history</span>
                    <span class="text-inherit transition-transform group-open:rotate-180" aria-hidden="true">&#8964;</span>
                </summary>
                <div class="mt-4 overflow-x-auto">
                    <x-shipment-tracking :shipment="$sellerOrder" />
                </div>
            </details>

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
    <div class="min-w-0 space-y-6">
        {{-- Current status --}}
        <section class="border border-[var(--border-strong)] rounded-2xl bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6 2xl:p-8">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Current status</h2>
            <p class="inline-flex rounded-full bg-primary-50 px-4 py-2 font-sans text-sm font-semibold text-primary-900 ring-1 ring-inset ring-primary-200">{{ $sellerOrder->status->label() }}</p>
            @if($sellerOrder->tracking_number)
                <p class="mt-2 font-mono text-[12.5px] text-[var(--text-ink)] break-all">{{ $sellerOrder->tracking_number }}</p>
                <a href="{{ route('seller.shipments.label', $sellerOrder) }}" target="_blank" rel="noopener"
                   class="mt-3 inline-flex min-h-10 items-center justify-center rounded-lg border border-primary-200 bg-primary-50 px-4 font-sans text-xs font-semibold text-primary-900 transition-colors hover:border-primary-700 hover:bg-primary-700 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                    Print waybill
                </a>
            @endif
            @if($sellerOrder->handover_at)
                <p class="mt-3 pt-3 border-t border-[var(--border-strong)] font-sans text-[11.5px] text-[var(--text-muted)]">
                    Handed to rider {{ $sellerOrder->handover_at->format('M d, Y h:i A') }}
                </p>
            @endif
        </section>

        @if ($sellerOrder->buyer_note)
            <section class="border border-[var(--border-strong)] rounded-2xl bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6 2xl:p-8">
                <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Note from buyer</h2>
                <p class="whitespace-pre-line break-words font-sans text-[13px] text-[var(--text-ink)]">{{ $sellerOrder->buyer_note }}</p>
            </section>
        @endif

        {{-- Actions --}}
        <section class="border border-[var(--border-strong)] rounded-2xl bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6 2xl:p-8">
            <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Actions</h2>
            <div class="flex flex-col gap-2">
                @if($sellerOrder->status === OrderStatus::Placed)
                    <form method="POST" action="{{ route('seller.orders.accept', $sellerOrder) }}" class="w-full">
                        @csrf
                        <button type="button"
                            class="w-full inline-flex items-center justify-center min-h-11 px-4 py-2 rounded-lg font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                            onclick="confirmAccept(this)">
                            Accept order
                        </button>
                    </form>
                    <form method="POST" action="{{ route('seller.orders.decline', $sellerOrder) }}" class="w-full">
                        @csrf
                        <label class="sr-only" for="decline-reason-{{ $sellerOrder->id }}">Decline reason (10–300 characters)</label>
                        <input type="text" name="reason" id="decline-reason-{{ $sellerOrder->id }}" value="{{ old('reason') }}" required minlength="10" maxlength="300"
                            placeholder="Reason (10–300 characters)"
                            class="mb-2 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-sans text-[12.5px] text-[var(--text-ink)]">
                        @error('reason')
                            <p class="mb-2 font-sans text-[12px] text-[#b91c1c]">{{ $message }}</p>
                        @enderror
                        <button type="button"
                            class="w-full inline-flex items-center justify-center min-h-11 px-4 py-2 rounded-lg font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-primary-800 border border-primary-800 bg-transparent hover:bg-primary-50"
                            onclick="confirmDecline(this)">
                            Decline order
                        </button>
                    </form>
                @elseif(in_array($sellerOrder->status, [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup]))
                    @if($sellerOrder->status !== OrderStatus::ReadyForPickup)
                        <form method="POST" action="{{ route($sellerOrder->status === OrderStatus::Confirmed ? 'seller.orders.pack' : 'seller.orders.markReady', $sellerOrder) }}" class="w-full">
                            @csrf
                            <button type="button"
                                class="w-full inline-flex items-center justify-center min-h-11 px-4 py-2 rounded-lg font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                                onclick="{{ $sellerOrder->status === OrderStatus::Confirmed ? 'this.form.submit()' : 'confirmMarkReady(this)' }}">
                                {{ $sellerOrder->status === OrderStatus::Confirmed ? 'Pack order' : 'Mark as ready for pickup' }}
                            </button>
                        </form>
                    @else
                        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Waiting for a rider to pick up this parcel.</p>
                    @endif
                    <form method="POST" action="{{ route('seller.orders.decline', $sellerOrder) }}" class="w-full">
                        @csrf
                        <label class="sr-only" for="decline-reason-pack-{{ $sellerOrder->id }}">Decline reason (10–300 characters)</label>
                        <input type="text" name="reason" id="decline-reason-pack-{{ $sellerOrder->id }}" value="{{ old('reason') }}" required minlength="10" maxlength="300"
                            placeholder="Reason (10–300 characters)"
                            class="mb-2 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-sans text-[12.5px] text-[var(--text-ink)]">
                        @error('reason')
                            <p class="mb-2 font-sans text-[12px] text-[#b91c1c]">{{ $message }}</p>
                        @enderror
                        <button type="button"
                            class="w-full inline-flex items-center justify-center min-h-11 px-4 py-2 rounded-lg font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-primary-800 border border-primary-800 bg-transparent hover:bg-primary-50"
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
                            This shipment was cancelled. Stock was restored.
                        @else
                            No further action is needed for this order.
                        @endif
                    </p>
                @endif

                {{-- Message buyer button --}}
                @if ($buyer)
                    <form method="POST" action="{{ route('seller.orders.message', $sellerOrder) }}" class="w-full">
                        @csrf
                        <button
                            type="submit"
                            class="w-full inline-flex items-center justify-center min-h-11 px-4 py-2 rounded-lg font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-primary-700 border border-primary-300 bg-primary-50 hover:bg-primary-100"
                        >
                            💬 Message buyer
                        </button>
                    </form>
                @endif
            </div>
        </section>

        {{-- Payment --}}
        @if($sellerOrder->payment)
            <section class="border border-[var(--border-strong)] rounded-2xl bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6 2xl:p-8">
                <h2 class="font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] mb-3">Payment</h2>
                <p class="font-sans text-[13.5px] text-[var(--text-ink)]">{{ $sellerOrder->payment->formattedAmount() }} (COD)</p>
                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Status: {{ $sellerOrder->payment->status->value }}</p>
            </section>
        @endif
    </div>
</div>
</div>
@endsection
