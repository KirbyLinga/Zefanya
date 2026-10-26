@extends('Layouts.rider')

@section('title', 'Pickup Assignments')

@use('App\Enums\OrderStatus')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Assignments</p>
            <h1 class="font-serif text-2xl text-[var(--text-ink)]">Pickup Assignments</h1>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                Parcels Logistics has reserved for you. Scan the waybill or confirm collection at the seller, then hand the parcel to your Station Hub.
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
            <i data-lucide="package-search" class="mb-3 h-[32px] w-[32px] text-[var(--icon-soft)]"></i>
            <p class="mb-1 font-sans text-[13px] font-semibold text-[var(--text-ink)]">No pickup assignments</p>
            <p class="max-w-[380px] font-sans text-[11.5px] text-[var(--text-muted)]">
                When Logistics assigns you a parcel that is ready at a seller, it will appear here.
            </p>
        </section>
    @else
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($shipments as $shipment)
                @php
                    $seller = $shipment->seller;
                    $addr = $shipment->order?->shipping_address ?? [];
                    $pickupAddress = collect([
                        $seller?->house_number,
                        $seller?->street,
                        $seller?->barangay_name,
                        $seller?->municipality_name,
                        $seller?->province_name,
                    ])->filter()->implode(', ');
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
                            <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Pickup from</p>
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">{{ $seller?->business_name ?? $seller?->name ?? 'Seller' }}</p>
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $pickupAddress !== '' ? $pickupAddress : 'Address not recorded' }}</p>
                            @if ($seller?->contact_no)
                                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $seller->contact_no }}</p>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Parcel</p>
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->formattedParcelWeight() }}</p>
                                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $shipment->orderItems->sum('quantity') }} item(s)</p>
                            </div>
                            <div>
                                <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Destination</p>
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $addr['municipality'] ?? '—' }}</p>
                            </div>
                        </div>
                    </div>

                    <footer class="flex flex-wrap items-center gap-2 border-t border-[var(--border-strong)] px-4 py-3">
                        <a href="{{ route('rider.waybills.show', $shipment) }}"
                           class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] no-underline hover:bg-[var(--bg-page)]">
                            Digital waybill
                        </a>
                        @if ($shipment->status === OrderStatus::ReadyForPickup)
                            <a href="{{ route('rider.scan.index') }}"
                               class="inline-flex h-9 items-center justify-center rounded-sm bg-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white no-underline hover:bg-primary-800">
                                Scan to confirm
                            </a>
                        @elseif ($shipment->status === OrderStatus::PickedUp)
                            <p class="font-sans text-[11.5px] font-semibold text-emerald-700">
                                Collected{{ $shipment->handover_at ? ' '.$shipment->handover_at->format('M j, g:i A') : '' }} — hand over at your Station Hub.
                            </p>
                        @endif
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
@endsection
