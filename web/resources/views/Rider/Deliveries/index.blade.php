@extends('Layouts.rider')

@section('title', 'Delivery Assignments')

@use('App\Enums\OrderStatus')
@use('App\Enums\PaymentMethod')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Assignments</p>
            <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery Assignments</h1>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                Parcels Logistics has assigned to you for the final leg. Scan the waybill to verify, then start the run and record proof of delivery.
                Maximum {{ \App\Services\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS }} total delivery attempts: the initial delivery and one retry.
            </p>
        </div>
        <a href="{{ route('rider.scan.index') }}"
            class="inline-flex h-9 shrink-0 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--bg-surface)]">
            Scan parcel
        </a>
    </div>

    <x-rider.flash />

    @if ($shipments->isEmpty())
        <section class="flex flex-col items-center rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center shadow-sm">
            <i data-lucide="truck" class="mb-3 h-[32px] w-[32px] text-[var(--icon-soft)]"></i>
            <p class="mb-1 font-sans text-[13px] font-semibold text-[var(--text-ink)]">No delivery assignments</p>
            <p class="max-w-[380px] font-sans text-[11.5px] text-[var(--text-muted)]">
                Parcels sorted and assigned to you by Logistics will appear here.
            </p>
        </section>
    @else
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($shipments as $shipment)
                @php
                    $addr = $shipment->order?->shipping_address ?? [];
                    $payment = $shipment->payment;
                    $collect = $payment?->requiresCollection() ?? false;
                @endphp

                <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
                    <header class="flex items-start justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-3">
                        <div class="min-w-0">
                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Parcel #{{ $shipment->id }}</p>
                            <p class="font-mono text-[10.5px] text-[var(--text-muted)]">{{ $shipment->tracking_number ?? 'No tracking number' }}</p>
                        </div>
                        <span class="shrink-0 rounded-full border border-[var(--border-strong)] px-2 py-0.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)]">
                            {{ $shipment->status->label() }}
                        </span>
                    </header>

                    <div class="space-y-3 px-4 py-3">
                        <div>
                            <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Deliver to</p>
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">{{ $addr['name'] ?? $shipment->order?->buyer?->fullName() ?? 'Buyer' }}</p>
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $addr['full'] ?? 'Address not recorded' }}</p>
                            @if (! empty($addr['phone']))
                                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $addr['phone'] }}</p>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Parcel</p>
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->formattedParcelWeight() }}</p>
                                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $shipment->orderItems->sum('quantity') }} item(s)</p>
                            </div>
                            <div>
                                <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Payment</p>
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">
                                    {{ $payment?->method?->label() ?? 'Not recorded' }}
                                </p>
                                @if ($collect)
                                    <p class="font-sans text-[11.5px] font-semibold text-amber-700">Collect {{ $payment->formattedAmount() }}</p>
                                @elseif ($payment?->method === PaymentMethod::Cod)
                                    <p class="font-sans text-[11.5px] text-emerald-700">Collected</p>
                                @endif
                            </div>
                        </div>

                        @if ($shipment->status === OrderStatus::DeliveryFailed)
                            <p class="font-sans text-[11.5px] text-rose-700">
                                Failed attempts: {{ (int) $shipment->delivery_attempts }} / {{ \App\Services\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS }}.
                                @if ((int) $shipment->delivery_attempts >= \App\Services\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS)
                                    Two attempts exhausted. Logistics must arrange return to seller.
                                @else
                                    Waiting for Logistics to schedule the final attempt or return the parcel.
                                @endif
                            </p>
                        @endif
                    </div>

                    <footer class="flex flex-wrap items-center gap-2 border-t border-[var(--border-strong)] px-4 py-3">
                        <a href="{{ route('rider.waybills.show', $shipment) }}"
                           class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] no-underline hover:bg-[var(--bg-page)]">
                            Digital waybill
                        </a>
                        @if ($shipment->status === OrderStatus::AssignedToRider || $shipment->status === OrderStatus::OutForDelivery)
                            <a href="{{ route('rider.scan.index') }}"
                               class="inline-flex h-9 items-center justify-center rounded-sm bg-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white no-underline hover:bg-primary-800">
                                Scan to confirm
                            </a>
                        @else
                            <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Logistics will decide the next step.</span>
                        @endif
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
@endsection
