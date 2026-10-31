@props(['status'])

{{-- One colour per order status, shared by every seller table that lists orders. --}}
@php
    $colors = match ($status) {
        \App\Enums\OrderStatus::Placed => 'bg-amber-100 text-amber-900 ring-amber-300',
        \App\Enums\OrderStatus::Confirmed => 'bg-lime-100 text-lime-900 ring-lime-300',
        \App\Enums\OrderStatus::Preparing => 'bg-yellow-100 text-yellow-900 ring-yellow-300',
        \App\Enums\OrderStatus::ReadyForPickup => 'bg-sky-100 text-sky-900 ring-sky-300',
        \App\Enums\OrderStatus::PickedUp => 'bg-indigo-100 text-indigo-900 ring-indigo-300',
        \App\Enums\OrderStatus::AtSortingCenter => 'bg-violet-100 text-violet-900 ring-violet-300',
        \App\Enums\OrderStatus::Sorted => 'bg-fuchsia-100 text-fuchsia-900 ring-fuchsia-300',
        \App\Enums\OrderStatus::AssignedToRider => 'bg-cyan-100 text-cyan-900 ring-cyan-300',
        \App\Enums\OrderStatus::OutForDelivery => 'bg-blue-100 text-blue-900 ring-blue-300',
        \App\Enums\OrderStatus::Delivered => 'bg-teal-100 text-teal-900 ring-teal-300',
        \App\Enums\OrderStatus::Completed => 'bg-green-100 text-green-900 ring-green-300',
        \App\Enums\OrderStatus::DeliveryFailed => 'bg-orange-100 text-orange-900 ring-orange-300',
        \App\Enums\OrderStatus::Returned => 'bg-stone-200 text-stone-900 ring-stone-400',
        \App\Enums\OrderStatus::Cancelled => 'bg-red-100 text-red-900 ring-red-300',
    };
@endphp

<span class="inline-flex max-w-full items-center justify-center px-3 py-1.5 rounded-full font-sans text-[10.5px] font-semibold tracking-[0.4px] uppercase ring-1 ring-inset {{ $colors }}">{{ $status->label() }}</span>
