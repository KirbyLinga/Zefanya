@props(['shipment', 'compact' => false])
@use('App\Enums\OrderStatus')
@php
    $guidance = $shipment->hasActiveReturn()
        ? 'Review the return request and follow its authorized return steps.'
        : match ($shipment->status) {
            OrderStatus::Placed => 'Accept the order to begin fulfillment, or review it before declining.',
            OrderStatus::Confirmed => 'Pack the order after checking the items and buyer note.',
            OrderStatus::Preparing => 'Finish packing, then mark the parcel ready for pickup.',
            OrderStatus::ReadyForPickup => 'Prepare the waybill and wait for the assigned pickup rider.',
            OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::InTransit, OrderStatus::ArrivedAtHub,
            OrderStatus::AssignedToRider, OrderStatus::OutForDelivery => 'Logistics is handling delivery. Follow the shipment timeline for updates.',
            OrderStatus::DeliveryFailed => 'Logistics will arrange the next authorized delivery or return step.',
            OrderStatus::Delivered => 'Delivered to the buyer; waiting for receipt confirmation or the return window to end.',
            OrderStatus::Completed => 'Fulfillment is complete. No further shipping action is needed.',
            OrderStatus::Returned => 'The returned parcel has been received. Review its return details.',
            OrderStatus::Cancelled => 'This order is cancelled. No pickup or delivery action is needed.',
            OrderStatus::Lost => 'Admin confirmed this parcel lost. No shipping action is needed. Any compensation owed to you is listed under Earnings.',
        };
    // Philippine-time window the parcel was booked into when it was marked ready.
    $pickupWindow = $shipment->status === OrderStatus::ReadyForPickup && ! $shipment->hasActiveReturn()
        ? \App\Services\Logistics\PickupWindowService::label($shipment) : null;
@endphp
<p data-fulfillment-guidance class="{{ $compact ? 'mt-2 text-[11px]' : 'mb-5 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-page)] p-4 text-sm' }} leading-relaxed text-[var(--text-muted)]"><span class="font-semibold text-[var(--text-ink)]">Next step:</span> {{ $guidance }}</p>
@if ($pickupWindow)
    <p data-pickup-window class="{{ $compact ? 'mt-1 text-[11px]' : '-mt-3 mb-5 text-sm' }} leading-relaxed text-[var(--text-muted)]"><span class="font-semibold text-[var(--text-ink)]">Pickup scheduled:</span> {{ $pickupWindow }}@if ($shipment->pickup_missed_windows > 0) <span class="text-[var(--text-muted)]">(moved to the next window; the earlier one passed)</span>@endif</p>
@endif
