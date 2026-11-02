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
            OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery => 'Logistics is handling delivery. Follow the shipment timeline for updates.',
            OrderStatus::DeliveryFailed => 'Logistics will arrange the next authorized delivery or return step.',
            OrderStatus::Delivered => 'Delivered to the buyer; waiting for receipt confirmation or the return window to end.',
            OrderStatus::Completed => 'Fulfillment is complete. No further shipping action is needed.',
            OrderStatus::Returned => 'The returned parcel has been received. Review its return details.',
            OrderStatus::Cancelled => 'This order is cancelled. No pickup or delivery action is needed.',
        };
@endphp
<p data-fulfillment-guidance class="{{ $compact ? 'mt-2 text-[11px]' : 'mb-5 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-page)] p-4 text-sm' }} leading-relaxed text-[var(--text-muted)]"><span class="font-semibold text-[var(--text-ink)]">Next step:</span> {{ $guidance }}</p>
