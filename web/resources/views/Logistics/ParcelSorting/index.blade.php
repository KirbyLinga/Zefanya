@extends('Layouts.logistics')

@section('title', 'Parcel Sorting')

@use('App\Enums\OrderStatus')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Parcel Sorting</h1>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<div class="mb-5 border-b border-[var(--border-strong)]">
    <a href="{{ route('logistics.parcel-sorting.index', ['tab' => 'sorting']) }}"
       class="px-4 py-2.5 -mb-px font-sans text-[12.5px] font-semibold tracking-[0.5px] border-b-2 transition-colors
             {{ $tab === 'sorting' ? 'border-[var(--link)] text-[var(--link)]' : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-ink)]' }}">
        At sorting center
        <span class="ml-2 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 bg-primary-800 text-white font-sans font-bold text-[10.5px] rounded-full">{{ $sortingCount }}</span>
    </a>
    <a href="{{ route('logistics.parcel-sorting.index', ['tab' => 'assigned']) }}"
       class="px-4 py-2.5 -mb-px font-sans text-[12.5px] font-semibold tracking-[0.5px] border-b-2 transition-colors
             {{ $tab === 'assigned' ? 'border-[var(--link)] text-[var(--link)]' : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-ink)]' }}">
        Assigned to rider
        <span class="ml-2 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 bg-primary-800 text-white font-sans font-bold text-[10.5px] rounded-full">{{ $assignedCount }}</span>
    </a>
</div>

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="archive" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">
            @if ($tab === 'sorting') No parcels at the sorting center
            @else No delivery assignments yet
            @endif
        </p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            @if ($tab === 'sorting')
                Parcels delivered to the sorting center by pickup riders will appear here for sorting and routing.
            @else
                Parcels assigned to delivery riders will appear here once sorted and routed.
            @endif
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
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Pickup courier</th>
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
                            @if ($shipment->courier)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->courier->name }}</p>
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ ucfirst($shipment->courier->vehicle_type) }}</p>
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
                            @if ($tab === 'sorting')
                                @if ($shipment->status === OrderStatus::AtSortingCenter)
                                    <form method="POST" action="{{ route('logistics.parcel-sorting.sort', $shipment) }}">
                                        @csrf
                                        <button type="submit"
                                            class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800 mb-1">
                                            Sort
                                        </button>
                                    </form>
                                @elseif ($shipment->status === OrderStatus::Sorted && ! $shipment->deliveryCourier)
                                    <form method="POST" action="{{ route('logistics.parcel-sorting.assignDelivery', $shipment) }}">
                                        @csrf
                                        <button type="submit"
                                            class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800">
                                            Assign Delivery Rider
                                        </button>
                                    </form>
                                @elseif ($shipment->status === OrderStatus::Sorted && $shipment->deliveryCourier)
                                    <p class="font-sans text-[11.5px] text-[var(--text-muted)]">
                                        {{ $shipment->deliveryCourier->name }} assigned
                                    </p>
                                @else
                                    <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                                @endif
                            @else
                                {{-- assigned tab: read-only, show delivery courier --}}
                                @if ($shipment->deliveryCourier)
                                    <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->deliveryCourier->name }}</p>
                                    <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ ucfirst($shipment->deliveryCourier->vehicle_type) }}</p>
                                @else
                                    <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
@endsection
