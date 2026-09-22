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
}
