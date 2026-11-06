<?php

namespace App\Enums;

/**
 * The order-fulfillment pipeline status. Shared by `orders`, `seller_orders`,
 * and `delivery_events` (the append-only shipment log).
 *
 * Stored as a native MySQL ENUM column per the Milestone 4 schema spec — a
 * deliberate deviation from the project's usual VARCHAR-only status rule,
 * with the permitted values mirrored here in application code.
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

    case AssignedToRider = 'ASSIGNED_TO_RIDER';

    case OutForDelivery = 'OUT_FOR_DELIVERY';

    case Delivered = 'DELIVERED';

    case Completed = 'COMPLETED';

    case DeliveryFailed = 'DELIVERY_FAILED';

    case Returned = 'RETURNED';

    /** Seller declined their slice before handover (terminal; stock restored). */
    case Cancelled = 'CANCELLED';

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
            self::AssignedToRider => 'Courier assigned',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::DeliveryFailed => 'Delivery failed',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Customer-facing stage without changing operational status labels.
     */
    public function buyerLabel(): string
    {
        return match ($this) {
            self::Placed, self::Confirmed, self::Preparing, self::ReadyForPickup => 'Preparing',
            self::PickedUp, self::AtSortingCenter, self::Sorted, self::AssignedToRider => 'Shipped',
            default => $this->label(),
        };
    }

    /** Shipment-state filter used by the buyer order list. */
    public function buyerTab(): string
    {
        return match ($this) {
            self::Placed, self::Confirmed, self::Preparing, self::ReadyForPickup => 'to_ship',
            self::PickedUp, self::AtSortingCenter, self::Sorted, self::AssignedToRider,
            self::OutForDelivery, self::Delivered => 'to_receive',
            self::Completed => 'completed',
            self::DeliveryFailed => 'delivery_failed',
            self::Returned => 'returned',
            self::Cancelled => 'cancelled',
        };
    }

    /** Broad operational bucket used by seller pages. */
    public function bucket(): string
    {
        return match ($this) {
            self::Placed, self::Confirmed, self::Preparing => 'to_ship',
            self::ReadyForPickup => 'ready',
            self::AssignedToRider, self::PickedUp, self::AtSortingCenter,
            self::Sorted, self::OutForDelivery => 'shipped',
            self::Delivered, self::Completed,
            self::DeliveryFailed, self::Returned => 'delivered',
            self::Cancelled => 'cancelled',
        };
    }
}
