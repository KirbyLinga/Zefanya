@props(['status'])

@use('App\Enums\OrderStatus')

@php
    $tone = match ($status) {
        OrderStatus::Cancelled, OrderStatus::Returned => 'border-red-200 bg-red-50 text-red-800',
        OrderStatus::DeliveryFailed, OrderStatus::ReadyForPickup => 'border-amber-200 bg-amber-50 text-amber-900',
        OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted,
        OrderStatus::AssignedToRider, OrderStatus::OutForDelivery => 'border-blue-200 bg-blue-50 text-blue-800',
        OrderStatus::Delivered, OrderStatus::Completed => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        default => 'border-secondary-200 bg-secondary-100 text-primary-900',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-md border px-2.5 py-1 font-sans text-xs font-semibold', $tone]) }}
      data-shipment-status="{{ $status->value }}">{{ $status->label() }}</span>
