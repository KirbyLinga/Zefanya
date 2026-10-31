@extends('Layouts.logistics')

@section('title', 'Delivery Monitoring')

@use('App\Enums\DeliveryFailureReason')
@use('App\Enums\OrderStatus')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery Monitoring</h1>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<form method="GET" action="{{ route('logistics.delivery-monitoring.index') }}" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 font-sans">
    <div class="w-full sm:max-w-md sm:flex-1">
        <label for="tracking-search" class="mb-2 block text-xs font-semibold text-[var(--text-ink)]">Find a shipment</label>
        <input id="tracking-search" name="search" type="search" value="{{ $search }}" maxlength="100" placeholder="Tracking number, order ID or buyer name" class="min-h-11 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)] focus:border-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-200">
        @error('search')<p role="alert" class="mt-1 text-xs text-red-800">{{ $message }}</p>@enderror
    </div>
    <button type="submit" class="min-h-11 rounded-lg bg-primary-800 px-5 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Search</button>
    @if ($search !== '')
        <a href="{{ route('logistics.delivery-monitoring.index') }}" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-[var(--link)] hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-primary-700">Clear search</a>
    @endif
    <p class="text-xs text-[var(--text-muted)] sm:mb-3 sm:ml-auto">{{ number_format($shipments->total()) }} {{ $shipments->total() === 1 ? 'shipment' : 'shipments' }}</p>
</form>
@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="activity" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">{{ $search !== '' ? 'No matching shipments' : 'No shipments to monitor' }}</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            {{ $search !== '' ? 'Try a different tracking number, order ID or buyer name, or clear the search.' : 'Delivery assignments and delivery updates for your logistics company appear here.' }}
        </p>
    </section>
@else
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full min-w-[1000px] text-left font-sans">
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
                            @can('advance-shipment', $shipment)
                                <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="rounded-md font-sans text-[12.5px] font-semibold text-[var(--link)] hover:underline focus-visible:outline-2 focus-visible:outline-primary-700">#{{ $shipment->id }} <span class="sr-only">View parcel details</span></a>
                            @else
                                <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                            @endcan
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
<div class="mt-5">{{ $shipments->links() }}</div>
@endsection
