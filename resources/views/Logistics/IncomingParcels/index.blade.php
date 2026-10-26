@extends('Layouts.logistics')

@section('title', 'Incoming Parcels')

@use('App\Enums\OrderStatus')

@section('content')
<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Incoming Parcels</h1>
    <a href="{{ route('logistics.scan.index') }}"
        class="inline-flex h-9 shrink-0 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--bg-page)] no-underline">
        Scan to Confirm
    </a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="inbox" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No parcels awaiting pickup</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Parcels marked ready for pickup by sellers will appear here for you to collect and deliver to the sorting center.
        </p>
    </section>
@else
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full min-w-[1100px] text-left font-sans">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Order</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Seller</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Pickup address</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Origin station</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Assigned Rider</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Parcel size</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Destination</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">State</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($shipments as $shipment)
                    @php
                        $stateLabel = $shipment->pickupStateLabel();
                        $originStation = $shipment->originStationName();
                        $pickupAddress = $shipment->pickupAddress();
                        $assignedRider = $shipment->courier?->rider;
                        $destinationMunicipality = $shipment->order?->shipping_address['municipality'] ?? $shipment->order?->buyer?->municipality_name;
                        $buyerName = $shipment->order?->buyer?->fullName();
                        $isClaimed = $shipment->isClaimedBy($provider);
                        $isUnassigned = $shipment->status === OrderStatus::ReadyForPickup && $shipment->courier_id === null;
                        $isAssignedAwaiting = $shipment->status === OrderStatus::ReadyForPickup && $shipment->courier_id !== null;
                        $isPickedUp = $shipment->status === OrderStatus::PickedUp;
                        $needsReassignment = $shipment->needs_reassignment;
                    @endphp
                    <tr class="group {{ (int) ($highlightedId ?? 0) === (int) $shipment->id ? 'bg-amber-100 ring-2 ring-amber-400' : '' }}" id="parcel-{{ $shipment->id }}">
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                            @if ($shipment->handover_at !== null && ! in_array($shipment->status, [\App\Enums\OrderStatus::Delivered, \App\Enums\OrderStatus::Completed, \App\Enums\OrderStatus::Returned, \App\Enums\OrderStatus::Cancelled], true))
                                @can('advance-shipment', $shipment)
                                    <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="text-xs font-semibold text-primary-600 hover:text-primary-800">Report incident</a>
                                @endcan
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? $shipment->seller?->name ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5 max-w-[200px]">
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)] truncate" title="{{ $pickupAddress }}">{{ $pickupAddress ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $originStation ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($assignedRider)
                                <p class="font-sans text-[11.5px] text-[var(--text-ink)]">{{ $assignedRider->fullName() }}</p>
                                <p class="font-sans text-[10.5px] text-[var(--text-muted)]">{{ $shipment->courier->name }}</p>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->formattedParcelWeight() }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($isClaimed && $buyerName)
                                <p class="font-sans text-[11.5px] text-[var(--text-ink)]">{{ $buyerName }}</p>
                            @endif
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $destinationMunicipality ?? '—' }}</p>
                            @if (! $isClaimed)
                                <p class="font-sans text-[10.5px] text-[var(--text-muted)]">Claim to reveal buyer details</p>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @php
                                $stateClass = match (true) {
                                    $needsReassignment => 'text-amber-700 bg-amber-50 border-amber-200',
                                    $isPickedUp => 'text-emerald-700 bg-emerald-50 border-emerald-200',
                                    $isAssignedAwaiting => 'text-primary-700 bg-primary-50 border-primary-200',
                                    default => 'text-[var(--text-muted)] bg-[var(--bg-page)] border-[var(--border-strong)]',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.5px] {{ $stateClass }}">
                                {{ $stateLabel }}
                            </span>
                            <x-logistics-dispatch-status :shipment="$shipment" role="pickup" />
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($isUnassigned)
                                @php
                                    $hubId = $originStationHubs[$shipment->id] ?? null;
                                    $hubCouriers = $availableCouriers->filter(fn ($courier): bool => $hubId !== null
                                        && (int) $courier->rider?->station_hub_id === (int) $hubId);
                                @endphp
                                <form method="POST" action="{{ route('logistics.incoming-parcels.assignPickupRider', $shipment) }}" class="mb-1">
                                    @csrf
                                    @if ($hubCouriers->isEmpty())
                                        <p class="font-sans text-[11.5px] text-[var(--text-muted)]">No eligible Rider at this Station Hub.</p>
                                    @else
                                        <select name="courier_id" required class="my-2 w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                                            <option value="">Select an eligible Rider</option>
                                            @foreach ($hubCouriers as $courier)
                                                <option value="{{ $courier->id }}" @selected((int) old('courier_id') === (int) $courier->id)>{{ $courier->rider?->fullName() ?? $courier->name }} · {{ number_format($courier->activeWeightGrams()) }}/{{ number_format($courier->max_weight_grams) }} g</option>
                                            @endforeach
                                        </select>
                                        <button type="submit"
                                            class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-emerald-700 hover:bg-emerald-800 w-full sm:w-auto">
                                            Assign Rider
                                        </button>
                                    @endif
                                </form>
                            @elseif ($isAssignedAwaiting || ($isPickedUp && ! $shipment->handover_at))
                                    <form method="POST" action="{{ route('logistics.incoming-parcels.assignPickupRider', $shipment) }}" class="mb-1">
                                        @csrf
                                        <input type="hidden" name="reassign" value="1">
                                        <input type="hidden" name="expected_courier_id" value="{{ $shipment->courier_id }}">
                                        <select name="courier_id" class="my-2 w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                                            <option value="">Select the next eligible Rider automatically</option>
                                            @foreach ($availableCouriers as $courier)
                                                @if ((int) $courier->rider?->station_hub_id === (int) $shipment->origin_station_hub_id && (int) $courier->id !== (int) $shipment->courier_id)
                                                    <option value="{{ $courier->id }}">{{ $courier->rider?->fullName() ?? $courier->name }} · {{ number_format($courier->activeWeightGrams()) }}/{{ number_format($courier->max_weight_grams) }} g</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <label class="block text-xs">Reassignment reason<input name="reason" required maxlength="500" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs" value="{{ old('reason') }}"></label>
                                        <button type="submit"
                                            class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-amber-700 hover:bg-amber-800 w-full sm:w-auto">
                                            Reassign Rider
                                        </button>
                                    </form>
                                @if ($isAssignedAwaiting)
                                    <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Awaiting rider collection</p>
                                @endif
                                @if ($isPickedUp)
                                <a href="{{ route('logistics.scan.index') }}"
                                    class="inline-flex items-center justify-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-emerald-700 hover:bg-emerald-800 w-full sm:w-auto no-underline">
                                    Scan to Confirm
                                </a>
                                @endif
                            @elseif ($isPickedUp)
                                <a href="{{ route('logistics.scan.index') }}" class="text-xs underline">Scan to confirm Station receipt</a>
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
<div class="mt-4">{{ $shipments->links() }}</div>
@endsection