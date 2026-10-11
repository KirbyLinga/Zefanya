@extends('Layouts.logistics')

@section('title', 'Delivery Assignments')

@use('App\Enums\OrderStatus')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery Assignments</h1>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="map-pin" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No delivery assignments</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Parcels assigned to your delivery couriers will appear here. Once sorted and assigned, start the delivery run.
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
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Delivery courier</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Tracking</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Status</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($shipments as $shipment)
                    @php($addr = $shipment->order?->shipping_address ?? [])
                    <tr class="group">
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->seller?->name ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @php($buyer = $shipment->order?->buyer)
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $buyer?->fullName() ?? '—' }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $addr['municipality'] ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->deliveryCourier)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->deliveryCourier->name }}</p>
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ ucfirst($shipment->deliveryCourier->vehicle_type) }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->tracking_number)
                                <p class="font-mono text-[10.5px] text-[var(--text-ink)]">{{ $shipment->tracking_number }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->status->label() }}</p>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            @if ($shipment->status === OrderStatus::AssignedToRider)
                                <form method="POST" action="{{ route('logistics.delivery-assignments.start', $shipment) }}">
                                    @csrf
                                    <button type="submit"
                                        class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800 mb-1">
                                        Start Delivery
                                    </button>
                                </form>
                            @elseif ($shipment->status === OrderStatus::OutForDelivery)
                                <form method="POST" action="{{ route('logistics.delivery-assignments.delivered', $shipment) }}" class="mb-1">
                                    @csrf
                                    <button type="submit"
                                        class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-emerald-700 hover:bg-emerald-800">
                                        Mark Delivered
                                    </button>
                                </form>
                                <a href="{{ route('logistics.delivery-assignments.failed.form', $shipment) }}"
                                   class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-rose-700 hover:bg-rose-800">
                                    Mark Failed
                                </a>
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
