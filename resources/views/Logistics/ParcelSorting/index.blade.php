@extends('Layouts.logistics')

@section('title', 'Parcel Sorting')

@use('App\Enums\OrderStatus')

@section('content')
<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Parcel Sorting</h1>
    <a href="{{ route('logistics.scan.index') }}"
        class="inline-flex h-9 shrink-0 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--bg-page)] no-underline">
        Scan to Confirm
    </a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

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
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full min-w-[1100px] text-left font-sans">
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
                            @if ($shipment->handover_at !== null && ! in_array($shipment->status, [\App\Enums\OrderStatus::Delivered, \App\Enums\OrderStatus::Completed, \App\Enums\OrderStatus::Returned, \App\Enums\OrderStatus::Cancelled], true))
                                @can('advance-shipment', $shipment)
                                    <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="text-xs font-semibold text-primary-600 hover:text-primary-800">Report incident</a>
                                @endcan
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @php($buyer = $shipment->order?->buyer)
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $buyer?->fullName() ?? '—' }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $addr['municipality'] ?? '—' }}</p>
                            @if (blank($shipment->buyerBarangayCode()) && in_array($shipment->status, [OrderStatus::AtSortingCenter, OrderStatus::Sorted], true) && ! $shipment->delivery_courier_id)
                                <a href="{{ route('logistics.parcel-sorting.address.preview', $shipment) }}" class="mt-1 block text-xs font-semibold text-amber-800">Verify original delivery barangay before assignment</a>
                            @endif
                            @if ($shipment->destinationArea)
                                <p class="font-sans text-[11px] text-[var(--link)]">Area: {{ $shipment->destinationArea->name }}</p>
                            @endif
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
                            <x-logistics-dispatch-status :shipment="$shipment" role="delivery" />
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            @if ($tab === 'sorting')
                                @if ($shipment->status === OrderStatus::AtSortingCenter)
                                    <a href="{{ route('logistics.scan.index') }}"
                                        class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800 mb-1 no-underline">
                                        Scan to Confirm
                                    </a>
                                @elseif ($shipment->status === OrderStatus::Sorted && ! $shipment->deliveryCourier)
                                    @php($dialogId = 'assign-rider-'.$shipment->id)
                                    @php($assignable = $availableCouriers->filter(fn ($courier): bool => $shipment->destination_area_id === null
                                        || (int) $courier->rider?->station_hub_id === (int) $shipment->destinationArea?->station_hub_id)->values())
                                    <button type="button" data-assign-open="{{ $dialogId }}"
                                        class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800">
                                        Assign Rider
                                    </button>
                                    <dialog id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title"
                                        class="m-auto w-[min(26rem,calc(100vw-2rem))] rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-0 text-[var(--text-ink)] shadow-xl backdrop:bg-black/40">
                                        <form method="POST" action="{{ route('logistics.parcel-sorting.assignDelivery', $shipment) }}" class="p-5">
                                            @csrf
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <h2 id="{{ $dialogId }}-title" class="font-serif text-lg text-[var(--text-ink)]">Assign Rider</h2>
                                                    <p class="mt-1 font-sans text-[11.5px] text-[var(--text-muted)]">
                                                        Parcel #{{ $shipment->id }}@if ($shipment->destinationArea) · {{ $shipment->destinationArea->name }}@endif
                                                    </p>
                                                </div>
                                                <button type="button" data-assign-close aria-label="Close"
                                                    class="shrink-0 rounded-sm p-1 font-sans text-[15px] leading-none text-[var(--text-muted)] hover:bg-[var(--bg-page)] hover:text-[var(--text-ink)]">✕</button>
                                            </div>

                                            <div class="mt-4 max-h-72 space-y-2 overflow-y-auto pr-1">
                                                <label class="flex cursor-pointer items-start gap-3 rounded-sm border border-[var(--border-strong)] p-3 hover:bg-[var(--bg-page)]">
                                                    <input type="radio" name="courier_id" value="" checked class="mt-0.5 accent-primary-700">
                                                    <span class="font-sans text-[12.5px] text-[var(--text-ink)]">
                                                        Auto-assign by area
                                                        <span class="block text-[11px] text-[var(--text-muted)]">Best available Rider for this parcel's destination area.</span>
                                                    </span>
                                                </label>
                                                @forelse ($assignable as $courier)
                                                    <label class="flex cursor-pointer items-start gap-3 rounded-sm border border-[var(--border-strong)] p-3 hover:bg-[var(--bg-page)]">
                                                        <input type="radio" name="courier_id" value="{{ $courier->id }}" class="mt-0.5 accent-primary-700">
                                                        <span class="font-sans text-[12.5px] text-[var(--text-ink)]">
                                                            {{ $courier->name }}
                                                            <span class="block text-[11px] text-[var(--text-muted)]">
                                                                {{ $courier->area?->name ?? 'No area' }} · {{ number_format($courier->activeWeightGrams()) }}/{{ number_format($courier->max_weight_grams) }} g
                                                            </span>
                                                        </span>
                                                    </label>
                                                @empty
                                                    <p class="rounded-sm border border-[var(--border-strong)] p-3 font-sans text-[11.5px] text-[var(--text-muted)]">
                                                        No eligible Rider is available at this Station Hub.
                                                    </p>
                                                @endforelse
                                            </div>

                                            <div class="mt-5 flex justify-end gap-2">
                                                <button type="button" data-assign-close
                                                    class="inline-flex h-8 items-center justify-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--bg-page)]">
                                                    Cancel
                                                </button>
                                                <button type="submit"
                                                    class="inline-flex h-8 items-center justify-center rounded-sm bg-primary-700 px-3 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800">
                                                    Assign Rider
                                                </button>
                                            </div>
                                        </form>
                                    </dialog>
                                @elseif ($shipment->status === OrderStatus::Sorted && $shipment->deliveryCourier)
                                    <p class="font-sans text-[11.5px] text-[var(--text-muted)]">
                                        {{ $shipment->deliveryCourier->name }} reserved
                                    </p>
                                    <form method="POST" action="{{ route('logistics.parcel-sorting.assignDelivery', $shipment) }}">@csrf<button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Confirm delivery dispatch</button></form>
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
<div class="mt-4">{{ $shipments->links() }}</div>
@endsection

@push('scripts')
<script>
(function () {
    function closeDialog(dialog) {
        if (dialog && dialog.open) dialog.close();
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-assign-open]');
        if (opener) {
            var dialog = document.getElementById(opener.getAttribute('data-assign-open'));
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            return;
        }

        var closer = event.target.closest('[data-assign-close]');
        if (closer) {
            closeDialog(closer.closest('dialog'));
            return;
        }

        // A click on the dialog element itself (not its form content) is a backdrop click.
        if (event.target instanceof HTMLDialogElement) {
            closeDialog(event.target);
        }
    });
})();
</script>
@endpush
