<?php

namespace App\Enums;

/**
 * The order-fulfillment pipeline status. Shared by `orders`, `seller_orders`,
 * and `delivery_events` (the append-only shipment log).
 *
 * Stored as VARCHAR(20); the permitted values live here in application code.
 * Case order is the pipeline order: the parent order rolls up to its least
 * advanced parcel by this order.
 */
enum OrderStatus: string
{
    case Placed = 'PLACED';

    case Confirmed = 'CONFIRMED';

    case Preparing = 'PREPARING';

    case ReadyForPickup = 'READY_FOR_PICKUP';

    case PickedUp = 'PICKED_UP';

    case AtSortingCenter = 'AT_SORTING_CENTER';

    case Sorted = 'SORTED';

    /** On a hub-to-hub transfer after a verified dispatch scan. */
    case InTransit = 'IN_TRANSIT';

    /** Verified by the receiving hub's scan (or a recovery scan); waits to be sorted or sent on. */
    case ArrivedAtHub = 'ARRIVED_AT_HUB';

    case AssignedToRider = 'ASSIGNED_TO_RIDER';

    case OutForDelivery = 'OUT_FOR_DELIVERY';

    case Delivered = 'DELIVERED';

    case Completed = 'COMPLETED';

    case DeliveryFailed = 'DELIVERY_FAILED';

    case Returned = 'RETURNED';

    /** Seller declined their slice before handover (terminal; stock restored). */
    case Cancelled = 'CANCELLED';

    /** Confirmed lost by Admin (terminal). Never reopened by a later scan; stock is not restored. */
    case Lost = 'LOST';

    /** Statuses a parcel can hold while it waits at a hub for sorting or its next transfer. */
    public const AT_HUB = [self::AtSortingCenter, self::Sorted, self::ArrivedAtHub];

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::ReadyForPickup => 'Ready for pickup',
            self::PickedUp => 'Picked up',
            self::AtSortingCenter => 'At sorting center',
            self::Sorted => 'Sorted',
            self::InTransit => 'In transit',
            self::ArrivedAtHub => 'Arrived at hub',
            self::AssignedToRider => 'Courier assigned',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::DeliveryFailed => 'Delivery failed',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
            self::Lost => 'Lost',
        };
    }

    /**
     * Customer-facing stage without changing operational status labels.
     * Lost stays on the Cancelled tab for now, under its own shipment label.
     */
    public function buyerLabel(): string
    {
        return match ($this) {
            self::Placed, self::Confirmed, self::Preparing, self::ReadyForPickup => 'Preparing',
            self::PickedUp, self::AtSortingCenter, self::Sorted, self::ArrivedAtHub, self::AssignedToRider => 'Shipped',
            self::InTransit => 'In transit',
            self::Lost => 'Lost — Shipment issue',
            default => $this->label(),
        };
    }

    /** Shipment-state filter used by the buyer order list. */
    public function buyerTab(): string
    {
        return match ($this) {
            self::Placed, self::Confirmed, self::Preparing, self::ReadyForPickup => 'to_ship',
            self::PickedUp, self::AtSortingCenter, self::Sorted, self::InTransit, self::ArrivedAtHub,
            self::AssignedToRider, self::OutForDelivery, self::Delivered => 'to_receive',
            self::Completed => 'completed',
            self::DeliveryFailed => 'delivery_failed',
            self::Returned => 'returned',
            self::Cancelled, self::Lost => 'cancelled',
        };
    }

    /** Broad operational bucket used by seller pages. */
    public function bucket(): string
    {
        return match ($this) {
            self::Placed, self::Confirmed, self::Preparing => 'to_ship',
            self::ReadyForPickup => 'ready',
            self::AssignedToRider, self::PickedUp, self::AtSortingCenter,
            self::Sorted, self::InTransit, self::ArrivedAtHub, self::OutForDelivery => 'shipped',
            self::Delivered, self::Completed,
            self::DeliveryFailed, self::Returned => 'delivered',
            self::Cancelled, self::Lost => 'cancelled',
        };
    }
}
