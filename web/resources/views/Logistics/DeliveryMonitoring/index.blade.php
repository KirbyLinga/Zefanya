@extends('Layouts.logistics')

@section('title', 'Delivery Monitoring')

@use('App\Enums\DeliveryFailureReason')
@use('App\Enums\OrderStatus')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery Monitoring</h1>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="activity" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No shipments to monitor</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Parcels claimed by your couriers will appear here from pickup through delivery, including failure reasons.
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
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Delivery rider</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Tracking</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Status</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Failure reason</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($shipments as $shipment)
                    @php($addr = $shipment->order?->shipping_address ?? [])
                    <tr class="group">
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                            @if ($shipment->handover_at !== null && ! in_array($shipment->status, [\App\Enums\OrderStatus::Delivered, \App\Enums\OrderStatus::Completed, \App\Enums\OrderStatus::Returned, \App\Enums\OrderStatus::Cancelled], true))
                                @can('advance-shipment', $shipment)
                                    <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="text-xs font-semibold text-primary-600 hover:text-primary-800">Report incident</a>
                                @endcan
                            @endif
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
                            @if ($shipment->courier && (int) $shipment->courier->logistics_provider_id === (int) request()->user('logistics')->id)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->courier->name }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->deliveryCourier && (int) $shipment->deliveryCourier->logistics_provider_id === (int) request()->user('logistics')->id)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->deliveryCourier->name }}</p>
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ ucfirst($shipment->deliveryCourier->vehicle_type) }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->tracking_number)
                                <p class="font-mono text-[11.5px] text-[var(--text-ink)]">{{ $shipment->tracking_number }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->status->label() }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $shipment->created_at->format('M d, Y') }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->delivery_failure_reason)
                                @php($reasonLabel = \App\Enums\DeliveryFailureReason::tryFrom($shipment->delivery_failure_reason)?->label() ?? $shipment->delivery_failure_reason)
                                <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">{{ $reasonLabel }}</p>
                                @if ($shipment->delivery_failure_notes)
                                    <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $shipment->delivery_failure_notes }}</p>
                                @endif
                            @elseif ($shipment->status === OrderStatus::DeliveryFailed)
                                {{-- Pre-reason-column legacy row: keep the cell honest instead of blank. --}}
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">No reason recorded</span>
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
