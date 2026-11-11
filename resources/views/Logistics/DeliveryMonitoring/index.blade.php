@extends('Layouts.logistics')

@section('title', 'Delivery Tracking')

@use('App\Enums\DeliveryFailureReason')
@use('App\Enums\OrderStatus')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
@include('Logistics._parcel-queue-assets')
@php
    $buttonBase = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors';
    $primaryButton = $buttonBase.' ip-primary';
    $quietButton = $buttonBase.' border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-page)]';
@endphp
<section class="ip-canvas min-w-0 [&_:is(a,button,input,select):focus-visible]:outline-2 [&_:is(a,button,input,select):focus-visible]:outline-offset-4 [&_:is(a,button,input,select):focus-visible]:outline-[var(--link)]" aria-label="Delivery tracking">
<x-logistics.tabs group="deliveries" />
<header class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div><p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Delivery operations</p><h1 class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Delivery Tracking</h1><p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Follow shipment progress, rider assignments and delivery exceptions.</p></div>
    <x-logistics.notification-bell />
</header>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<form method="GET" action="{{ route('logistics.delivery-monitoring.index') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 font-sans shadow-sm sm:p-[22px]">
    <div class="w-full sm:max-w-md sm:flex-1">
        <label for="tracking-search" class="mb-2 block text-xs font-semibold text-[var(--text-ink)]">Find a shipment</label>
        <input id="tracking-search" name="search" type="search" value="{{ $search }}" maxlength="100" placeholder="Tracking number, order ID or buyer name" class="min-h-11 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)] focus:border-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-200">
        @error('search')<p role="alert" class="mt-1 text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
    </div>
    <button type="submit" class="{{ $primaryButton }}">Search</button>
    @if ($search !== '')
        <a href="{{ route('logistics.delivery-monitoring.index') }}" class="{{ $quietButton }}">Clear search</a>
    @endif
    <p class="text-xs text-[var(--text-muted-2)] sm:mb-3 sm:ml-auto">{{ number_format($shipments->total()) }} {{ $shipments->total() === 1 ? 'shipment' : 'shipments' }}{{ $search !== '' ? ' matching search' : '' }}</p>
</form>
<section class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm" aria-labelledby="tracking-queue-heading">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <h2 id="tracking-queue-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">Shipment tracking queue</h2>
        <span class="font-sans text-[12px] text-[var(--text-muted-2)]">{{ $search !== '' ? 'Search results' : 'Your logistics shipments' }}</span>
    </div>
@if ($shipments->isEmpty())
    <div class="flex flex-col items-center px-[22px] py-[50px] text-center">
        <span class="mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="activity" class="h-[25px] w-[25px]" aria-hidden="true"></i></span>
        <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">{{ $shipments->total() > 0 ? 'No shipments on this page' : ($search !== '' ? 'No matching shipments' : 'No shipments to monitor') }}</h3>
        <p class="mb-[22px] mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">{{ $shipments->total() > 0 ? 'There are shipments on an earlier page of these results.' : ($search !== '' ? 'Try a different tracking number, order ID or buyer name, or clear the search.' : 'Delivery assignments and delivery updates for your logistics company appear here.') }}</p>
        @if ($shipments->total() > 0)
            <a href="{{ $shipments->url(1) }}" class="{{ $quietButton }}">Back to first page</a>
        @elseif ($search !== '')
            <a href="{{ route('logistics.delivery-monitoring.index') }}" class="{{ $quietButton }}">Clear search</a>
        @else
            <a href="{{ route('logistics.delivery-assignments.index') }}" class="{{ $quietButton }}">Go to Assign deliveries</a>
        @endif
    </div>
@else
    <p class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] px-4 py-3 font-sans text-[11px] text-[var(--text-muted-2)] sm:px-[22px]">Scroll horizontally to see all shipment details.</p>
    <div role="region" aria-label="Shipment tracking table" tabindex="0" class="overflow-x-auto overscroll-x-contain focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--link)]">
        <table class="w-full min-w-[1100px] table-fixed text-left font-sans [&_td]:[overflow-wrap:anywhere]">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Order</th>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Seller</th>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Buyer · Destination</th>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Pickup rider</th>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Delivery rider</th>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Tracking</th>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Status</th>
                    <th scope="col" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted-2)] px-4 py-2.5">Failure reason</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($shipments as $shipment)
                    @php
                        $addr = $shipment->order?->shipping_address ?? [];
                        $statusTone = match ($shipment->status) {
                            OrderStatus::DeliveryFailed => 'bg-[var(--pill-danger-bg)] text-[var(--status-danger)]',
                            OrderStatus::Delivered, OrderStatus::Completed => 'bg-[var(--pill-success-bg)] text-[var(--status-success)]',
                            OrderStatus::OutForDelivery => 'bg-[var(--pill-warning-bg)] text-[var(--status-warning)]',
                            default => 'bg-[var(--bg-chip)] text-[var(--text-ink)]',
                        };
                    @endphp
                    <tr class="group transition-colors hover:bg-[var(--bg-raised)]">
                        <td class="px-4 py-4 align-top">
                            @can('advance-shipment', $shipment)
                                <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="inline-flex min-h-11 items-center rounded-md font-sans text-[12.5px] font-semibold text-[var(--link)] hover:underline focus-visible:outline-2 focus-visible:outline-primary-700">#{{ $shipment->id }} <span class="sr-only">View parcel details</span></a>
                            @else
                                <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                            @endcan
                            @if ($shipment->handover_at !== null && ! in_array($shipment->status, [\App\Enums\OrderStatus::Delivered, \App\Enums\OrderStatus::Completed, \App\Enums\OrderStatus::Returned, \App\Enums\OrderStatus::Cancelled], true))
                                @can('advance-shipment', $shipment)
                                    <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="inline-flex min-h-11 items-center text-xs font-semibold text-[var(--link)] hover:underline">Report incident</a>
                                @endcan
                            @endif
                        </td>
                        <td class="px-4 py-4 align-top">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->seller?->name ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-4 align-top">
                            @php($buyer = $shipment->order?->buyer)
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $buyer?->fullName() ?? '—' }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted-2)]">{{ $addr['municipality'] ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-4 align-top">
                            @if ($shipment->courier && (int) $shipment->courier->logistics_provider_id === (int) request()->user('logistics')->id)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->courier->name }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted-2)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 align-top">
                            @if ($shipment->deliveryCourier && (int) $shipment->deliveryCourier->logistics_provider_id === (int) request()->user('logistics')->id)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->deliveryCourier->name }}</p>
                                <p class="font-sans text-[11px] text-[var(--text-muted-2)]">{{ ucfirst($shipment->deliveryCourier->vehicle_type) }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted-2)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 align-top">
                            @if ($shipment->tracking_number)
                                <p class="[overflow-wrap:anywhere] font-mono text-[11.5px] text-[var(--text-ink)]">{{ $shipment->tracking_number }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted-2)]">pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 align-top">
                            <p class="{{ $statusTone }} inline-flex rounded-md px-2 py-1 font-sans text-[11px] font-semibold">{{ $shipment->status->label() }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted-2)]">{{ $shipment->created_at->format('M d, Y') }}</p>
                        </td>
                        <td class="px-4 py-4 align-top">
                            @if ($shipment->delivery_failure_reason)
                                @php($reasonLabel = \App\Enums\DeliveryFailureReason::tryFrom($shipment->delivery_failure_reason)?->label() ?? $shipment->delivery_failure_reason)
                                <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">{{ $reasonLabel }}</p>
                                @if ($shipment->delivery_failure_notes)
                                    <p class="font-sans text-[11px] text-[var(--text-muted-2)]">{{ $shipment->delivery_failure_notes }}</p>
                                @endif
                            @elseif ($shipment->status === OrderStatus::DeliveryFailed)
                                {{-- Pre-reason-column legacy row: keep the cell honest instead of blank. --}}
                                <span class="font-sans text-[11.5px] text-[var(--text-muted-2)]">No reason recorded</span>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted-2)]">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@if ($shipments->total() > 0)
    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <p class="font-sans text-[12px] text-[var(--text-muted-2)]">Showing {{ $shipments->firstItem() ?? 0 }} to {{ $shipments->lastItem() ?? 0 }} of {{ number_format($shipments->total()) }} shipments</p>
        {{ $shipments->links() }}
    </footer>
@endif
</section>
</section>
@endsection
