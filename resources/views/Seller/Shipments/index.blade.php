@extends('Layouts.seller')

@section('title', 'Shipments')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Shipments</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)] mt-1">Every parcel your shop is shipping. Print the waybill before handover.</p>
    </div>
</div>

@if ($shipments->isEmpty())
    <div class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-8 text-center">
        <p class="font-sans text-[13px] text-[var(--text-muted)]">No shipments yet. New orders appear here as soon as a buyer checks out.</p>
    </div>
@else
    <div class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] overflow-hidden">
        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-[var(--border-strong)] text-[11px] uppercase tracking-[0.6px] text-[var(--text-muted)]">
                    <th class="px-4 py-3 font-semibold">Tracking</th>
                    <th class="px-4 py-3 font-semibold">Order</th>
                    <th class="px-4 py-3 font-semibold">Buyer</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 font-semibold">Courier</th>
                    <th class="px-4 py-3 font-semibold text-right">Waybill</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shipments as $shipment)
                    <tr class="border-b border-[var(--border-strong)] last:border-0">
                        <td class="px-4 py-3 font-mono text-[12px] text-[var(--text-ink)] break-all">{{ $shipment->tracking_number ?? '—' }}</td>
                        <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-ink)]">
                            <a href="{{ route('seller.orders.show', $shipment) }}" class="text-[var(--link)] font-semibold">#{{ $shipment->id }}</a>
                        </td>
                        <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $shipment->order?->buyer?->fullName() ?? '—' }}</td>
                        <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->status->label() }}</td>
                        <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $shipment->courier?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('seller.shipments.label', $shipment) }}" target="_blank"
                               class="inline-flex items-center h-8 px-3 rounded-sm border border-[var(--border-strong)] font-sans text-[11.5px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-surface-hover,rgba(0,0,0,0.03))]">
                                Print label
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $shipments->links() }}</div>
@endif
@endsection
