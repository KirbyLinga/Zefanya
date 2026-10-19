@extends('Layouts.logistics')

@section('title', 'Incoming Parcels')

@use('App\Enums\OrderStatus')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Incoming Parcels</h1>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="inbox" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No parcels awaiting pickup</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Parcels marked ready for pickup by sellers will appear here for you to collect and deliver to the sorting center.
        </p>
    </section>
@else
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-left font-sans">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Order</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Seller</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Buyer · Destination</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Parcel size</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Status</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($shipments as $shipment)
                    <tr class="group">
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->seller?->name ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @php($addr = $shipment->order?->shipping_address ?? [])
                            {{-- Unclaimed parcels (any provider may still take them):
                                 show only decision inputs — destination area +
                                 parcel size. Buyer identity stays hidden until
                                 this provider's fleet claims the parcel. --}}
                            @if ($shipment->isClaimedBy($provider))
                                @php($buyer = $shipment->order?->buyer)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $buyer?->fullName() ?? '—' }}</p>
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $addr['municipality'] ?? '—' }}</p>
                            @else
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $addr['municipality'] ?? '—' }}</p>
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">Claim to reveal buyer details</p>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->formattedParcelWeight() }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->status->label() }}</p>
                            @if ($shipment->courier)
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">Pickup: {{ $shipment->courier->name }}</p>
                            @endif
                            @if ($shipment->tracking_number)
                                <p class="font-mono text-[10.5px] text-[var(--text-muted)]">{{ $shipment->tracking_number }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->status === OrderStatus::ReadyForPickup)
                                <form method="POST" action="{{ route('logistics.incoming-parcels.pickup', $shipment) }}">
                                    @csrf
                                    <button type="submit"
                                        class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800">
                                        Assign &amp; Pick Up
                                    </button>
                                </form>
                            @elseif ($shipment->status === OrderStatus::PickedUp)
                                <form method="POST" action="{{ route('logistics.incoming-parcels.toSortingCenter', $shipment) }}">
                                    @csrf
                                    <input type="hidden" name="expected_status" value="{{ $shipment->status->value }}">
                                    <button type="submit"
                                        class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-emerald-700 hover:bg-emerald-800">
                                        Hand to Sorting Center
                                    </button>
                                </form>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
@endsection
