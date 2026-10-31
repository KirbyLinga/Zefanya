@extends('Layouts.seller')

@section('title', 'Shipments')

@section('content')
<div class="mx-auto w-full max-w-[1440px]">
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Shipments</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)] mt-1">Track shipment progress and print waybills before courier handover.</p>
    </div>
</div>

<form method="GET" action="{{ route('seller.shipments.index') }}" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 font-sans">
    <div class="w-full sm:w-64">
        <label for="shipment-status" class="mb-2 block text-xs font-semibold text-[var(--text-ink)]">Shipment status</label>
        <select id="shipment-status" name="status" class="min-h-11 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)] focus:border-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-200">
            <option value="">All shipment statuses</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected($selectedStatus === $status)>{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="min-h-11 rounded-lg bg-primary-800 px-5 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Apply filter</button>
    @if ($selectedStatus)
        <a href="{{ route('seller.shipments.index') }}" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-[var(--link)] hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-primary-700">Clear filter</a>
    @endif
    <p class="text-xs text-[var(--text-muted)] sm:mb-3 sm:ml-auto">{{ number_format($shipments->total()) }} shipments</p>
</form>
@if ($shipments->isEmpty())
    <div class="border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)] p-8 text-center">
        <p class="font-sans text-[13px] text-[var(--text-muted)]">{{ $selectedStatus ? 'No shipments match this status. Try another status or clear the filter.' : 'No shipments to display.' }}</p>
    </div>
@else
    <div class="border border-[var(--border-strong)] rounded-xl bg-[var(--bg-surface)] overflow-x-auto shadow-sm">
        <table class="w-full min-w-[800px] table-fixed text-center [&_td]:align-middle">
            <thead class="bg-[var(--bg-hover)]">
                <tr class="border-b border-[var(--border-strong)] text-[11px] uppercase tracking-[0.6px] text-[var(--text-muted)]">
                    <th class="px-4 py-4 font-semibold">Tracking</th>
                    <th class="px-4 py-4 font-semibold">Order</th>

                    <th class="px-4 py-4 font-semibold">Shipment status</th>
                    <th class="px-4 py-4 font-semibold">Assigned courier</th>
                    <th class="px-4 py-4 font-semibold text-center">Waybill</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shipments as $shipment)
                    <tr class="border-b border-[var(--border-strong)] last:border-0 transition-colors hover:bg-[var(--bg-hover)]">
                        <td class="px-4 py-4 font-mono text-[12px] text-[var(--text-ink)] break-all">{{ $shipment->tracking_number ?? 'Not available' }}</td>
                        <td class="px-4 py-4 font-sans text-[12.5px] text-[var(--text-ink)]">
                            <a href="{{ route('seller.orders.show', $shipment) }}" class="rounded-md px-2 py-1 text-[var(--link)] font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-primary-700">#{{ $shipment->id }}</a>
                        </td>
                        <td class="px-4 py-4 font-sans text-[12.5px]">
                            @php
                                $statusTone = match ($shipment->status->name) {
                                    'Delivered', 'Completed' => 'bg-teal-50 text-teal-900 ring-teal-200',
                                    'ReadyForPickup' => 'bg-sky-50 text-sky-900 ring-sky-200',
                                    'PickedUp', 'AtSortingCenter', 'Sorted', 'AssignedToRider', 'OutForDelivery' => 'bg-indigo-50 text-indigo-900 ring-indigo-200',
                                    'Cancelled', 'DeliveryFailed' => 'bg-red-50 text-red-900 ring-red-200',
                                    'Returned' => 'bg-orange-50 text-orange-900 ring-orange-200',
                                    default => 'bg-neutral-100 text-neutral-900 ring-neutral-200',
                                };
                            @endphp
                            <span class="inline-flex items-center justify-center rounded-full px-3 py-1.5 text-[11px] font-semibold leading-relaxed ring-1 ring-inset {{ $statusTone }}">{{ $shipment->status->label() }}</span>
                        </td>
                        <td class="px-4 py-4 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $shipment->courier?->name ?? 'Not assigned' }}</td>
                        <td class="px-4 py-4 text-center">
                            <a href="{{ route('seller.shipments.label', $shipment) }}" target="_blank" rel="noopener"
                               class="inline-flex min-h-9 items-center justify-center rounded-lg border border-primary-200 bg-primary-50 px-4 font-sans text-[11.5px] font-semibold text-primary-900 transition-colors hover:border-primary-700 hover:bg-primary-700 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                                Print waybill
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $shipments->links() }}</div>
@endif
</div>
@endsection
