@extends('Layouts.logistics')

@section('title', 'Delivery Assignments')

@use('App\Enums\OrderStatus')
@use('App\Enums\DeliveryFailureReason')
@use('App\Enums\PaymentMethod')
@use('App\Enums\PaymentStatus')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery Assignments</h1>
</div>

<p class="mb-4 font-sans text-[12.5px] text-[var(--text-muted)]">Maximum {{ \App\Services\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS }} total delivery attempts: the initial delivery and one retry.</p>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="map-pin" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No delivery assignments</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">Parcels assigned to your delivery Riders will appear here.</p>
    </section>
@else
    <section class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[1320px] text-left font-sans">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Parcel / Tracking</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Seller</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Buyer · Destination</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Station Hub</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Assigned Rider</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Weight</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Delivery state</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Proof</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">COD</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Logistics action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($shipments as $shipment)
                    @php($addr = $shipment->order?->shipping_address ?? [])
                    @php($station = $shipment->destinationArea?->station ?? $shipment->deliveryCourier?->rider?->station)
                    @php($payment = $shipment->payment)
                    @php($weightGrams = $shipment->parcelWeightGrams())
                    <tr class="group">
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                            <p class="font-mono text-[10.5px] text-[var(--text-muted)]">{{ $shipment->tracking_number ?? 'No tracking number' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @php($buyer = $shipment->order?->buyer)
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $buyer?->fullName() ?? '—' }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $addr['municipality'] ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $station?->name ?? 'Not recorded' }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $station?->municipality ?? 'Location not recorded' }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->deliveryCourier)
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->deliveryCourier->rider?->fullName() ?? 'Rider account not linked' }}</p>
                                @if ($shipment->deliveryCourier->rider)
                                    <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ ucfirst($shipment->deliveryCourier->vehicle_type) }}</p>
                                @endif
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Unassigned</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-sans text-[12.5px] text-[var(--text-ink)]">{{ $weightGrams > 0 ? $shipment->formattedParcelWeight() : 'Not recorded' }}</td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->status->label() }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($shipment->deliveryProof)
                                <span class="block font-sans text-[11.5px] font-semibold text-emerald-700">Recorded</span>
                                <a href="{{ route('logistics.proofs.show', $shipment->deliveryProof) }}" target="_blank" rel="noopener noreferrer" class="font-sans text-[11px] font-semibold text-primary-800 hover:underline">View proof</a>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Not recorded</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($payment === null)
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Not recorded</span>
                            @elseif ($payment->method !== PaymentMethod::Cod)
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Not COD</span>
                            @elseif ($payment->collected_at !== null)
                                <span class="font-sans text-[11.5px] font-semibold text-emerald-700">Collected · {{ $payment->formattedAmount() }}</span>
                            @elseif ($payment->status === PaymentStatus::Paid)
                                <span class="font-sans text-[11.5px] font-semibold text-emerald-700">Paid</span>
                            @else
                                <span class="font-sans text-[11.5px] font-semibold text-amber-700">Due · {{ $payment->formattedAmount() }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-logistics-dispatch-status :shipment="$shipment" role="delivery" />
                            @if ($shipment->delivery_courier_id && in_array($shipment->status, [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery], true))
                                    <form method="POST" action="{{ route('logistics.delivery-assignments.reassign', $shipment) }}" class="mb-2 flex flex-col gap-2">
                                        @csrf
                                        <input type="hidden" name="expected_courier_id" value="{{ $shipment->delivery_courier_id }}">
                                        <label class="text-xs">Reassignment reason<input name="reason" required maxlength="500" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs" value="{{ old('reason') }}"></label>
                                        <select name="courier_id" class="h-8 max-w-60 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-2 font-sans text-[11px] text-[var(--text-ink)]">
                                            <option value="">Auto-assign eligible Rider</option>
                                            @foreach ($availableCouriers as $courier)
                                                <option value="{{ $courier->id }}">{{ $courier->rider?->fullName() ?? $courier->name }} · {{ $courier->rider?->station?->name ?? 'No station' }} · {{ number_format($courier->activeWeightGrams()) }}/{{ number_format($courier->max_weight_grams) }} g</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="inline-flex h-8 w-fit items-center justify-center rounded-sm bg-amber-700 px-3 font-sans text-[11px] font-semibold uppercase tracking-wide text-white hover:bg-amber-800">Reassign Rider</button>
                                    </form>
                            @endif
                            @if ($shipment->status === OrderStatus::Sorted && $shipment->delivery_courier_id)
                                <form method="POST" action="{{ route('logistics.parcel-sorting.assignDelivery', $shipment) }}">@csrf<button class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white" type="submit">Confirm delivery dispatch</button></form>
                            @elseif ($shipment->status === OrderStatus::AssignedToRider)
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Waiting for Rider to start</span>
                            @elseif ($shipment->status === OrderStatus::OutForDelivery)
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Rider is completing the delivery</span>
                            @elseif ($shipment->status === OrderStatus::DeliveryFailed)
                                <p class="mb-1 font-sans text-[11px] text-[var(--text-muted)]">Failed attempts: {{ (int) $shipment->delivery_attempts }} / {{ \App\Services\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS }}</p>
                                <p class="mb-2 font-sans text-[11px] text-rose-700">{{ DeliveryFailureReason::tryFrom((string) $shipment->delivery_failure_reason)?->label() ?? 'No reason recorded' }}</p>
                                @if ($shipment->delivery_failure_notes)
                                    <p class="mb-2 max-w-52 font-sans text-[11px] text-[var(--text-muted)]">{{ $shipment->delivery_failure_notes }}</p>
                                @endif
                                @if (\App\Services\SellerOrderStatusService::canRetryDelivery($shipment))
                                    <form method="POST" action="{{ route('logistics.delivery-assignments.reschedule', $shipment) }}" class="mb-2 flex flex-col gap-2">
                                        @csrf
                                        <input name="reason" maxlength="500" aria-label="Reason for manually choosing this Rider" placeholder="Reason for a manual Rider choice" class="w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                                        <select name="courier_id" class="h-8 max-w-60 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-2 font-sans text-[11px] text-[var(--text-ink)]">
                                            <option value="">Auto-assign eligible Rider</option>
                                            @foreach ($availableCouriers as $courier)
                                                <option value="{{ $courier->id }}">{{ $courier->rider?->fullName() ?? $courier->name }} · {{ $courier->rider?->station?->name ?? 'No station' }} · {{ number_format($courier->activeWeightGrams()) }}/{{ number_format($courier->max_weight_grams) }} g</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="inline-flex h-8 w-fit items-center justify-center rounded-sm bg-amber-700 px-3 font-sans text-[11px] font-semibold uppercase tracking-wide text-white hover:bg-amber-800">Schedule final attempt</button>
                                    </form>
                                @endif
                                @if ((int) $shipment->delivery_attempts >= \App\Services\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS)
                                    <p class="mb-2 font-sans text-[11px] text-rose-700">Two attempts exhausted. Physical return required; verified Seller receipt is needed.</p>
                                @elseif (! \App\Services\SellerOrderStatusService::canRetryDelivery($shipment) && ! $shipment->hasActiveReturn())
                                    <p class="mb-2 font-sans text-[11px] text-rose-700">Review the incomplete failure history before retrying.</p>
                                @endif
                                @if ($shipment->hasActiveReturn())
                                    <p class="mb-2 font-sans text-[11px] text-[var(--text-muted)]">{{ $shipment->parcelReturn->status->label() }}</p>
                                    <a href="{{ route('logistics.returns.show', $shipment->parcelReturn) }}" class="inline-flex rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Continue physical return</a>
                                @else
                                <form method="POST" action="{{ route('logistics.delivery-assignments.return', $shipment) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex h-8 items-center justify-center rounded-sm bg-rose-700 px-3 font-sans text-[11px] font-semibold uppercase tracking-wide text-white hover:bg-rose-800" onclick="return confirm('Request physical return transport? Completion requires verified Seller receipt.');">Request physical return</button>
                                </form>
                                @endif
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </section>
@endif
<div class="mt-4">{{ $shipments->links() }}</div>
@endsection
