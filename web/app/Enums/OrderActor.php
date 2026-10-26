<?php

namespace App\Enums;

/**
 * WHO performs a seller_order status transition. Used by
 * SellerOrderStatusService to enforce that each actor only runs the hops
 * the pipeline assigns to it, and recorded on status_histories rows.
 */
enum OrderActor: string
{
    case Seller = 'seller';
    case Logistics = 'logistics';
    case Rider = 'rider';
    case Buyer = 'buyer';
    case System = 'system';

    /**
     * The transitions (from → to) this actor is allowed to perform.
     * The map is intentionally explicit: an actor either owns a hop or is
     * rejected. System owns only automatic receipt completion, with the
     * waiting period and eligibility checked by the shared status service.
     */
    public static function allowedTransitions(self $actor): array
    {
        return match ($actor) {
            // Seller drives the packing pipeline up to handing the parcel over.
            // NOTE: Placed → ReadyForPickup is not in the spec'd seller list,
            // but the live seller flow packs straight from a fresh PLACED order
            // (the status graph and CourierAssignmentTest have always allowed
            // it) — keep it until a confirm/preparing UI step is mandatory.
            self::Seller => [
                [OrderStatus::DeliveryFailed, OrderStatus::Returned],
                [OrderStatus::Placed, OrderStatus::Confirmed],
                [OrderStatus::Confirmed, OrderStatus::Preparing],
                [OrderStatus::Preparing, OrderStatus::ReadyForPickup],
                [OrderStatus::Placed, OrderStatus::ReadyForPickup],
                // Decline before handover — cancels this seller's slice only.
                [OrderStatus::Placed, OrderStatus::Cancelled],
                [OrderStatus::Confirmed, OrderStatus::Cancelled],
                [OrderStatus::Preparing, OrderStatus::Cancelled],
            ],

            // Rider owns pickup collection and the delivery leg.
            // Station receipt (PickedUp → AtSortingCenter) is Logistics custody.
            self::Rider => [
                [OrderStatus::ReadyForPickup, OrderStatus::PickedUp],
                [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery],
                [OrderStatus::OutForDelivery, OrderStatus::Delivered],
                [OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed],
            ],

            // Logistics confirms Station Hub receipt, sorts, assigns delivery,
            // and owns after-failure recovery (reschedule / return).
            self::Logistics => [
                [OrderStatus::PickedUp, OrderStatus::AtSortingCenter],
                [OrderStatus::AtSortingCenter, OrderStatus::Sorted],
                [OrderStatus::Sorted, OrderStatus::AssignedToRider],
                [OrderStatus::DeliveryFailed, OrderStatus::AssignedToRider],
                [OrderStatus::DeliveryFailed, OrderStatus::Returned],
            ],

            // Buyer confirms receipt, and may cancel a still-unaccepted slice.
            self::Buyer => [
                [OrderStatus::Delivered, OrderStatus::Completed],
                [OrderStatus::Placed, OrderStatus::Cancelled],
            ],

            // Automatic receipt completion after the waiting period.
            // The System actor no longer assigns directly from ReadyForPickup;
            // assignment now happens via CourierAssignmentService with the
            // proper actor (Rider for pickup, Logistics for delivery).
            self::System => [
                [OrderStatus::Delivered, OrderStatus::Completed],
            ],
        };
    }

    /**
     * True when this actor is allowed to move a seller_order from → to.
     */
    public function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        foreach (self::allowedTransitions($this) as [$allowedFrom, $allowedTo]) {
            if ($allowedFrom === $from && $allowedTo === $to) {
                return true;
            }
        }

        return false;
    }
}
