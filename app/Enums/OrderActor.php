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
     * rejected — there is no wildcard except for the System actor, which owns
     * the assignment hops the code performs automatically (courier
     * assignment by CourierAssignmentService).
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
                [OrderStatus::Placed, OrderStatus::Confirmed],
                [OrderStatus::Confirmed, OrderStatus::Preparing],
                [OrderStatus::Preparing, OrderStatus::ReadyForPickup],
                [OrderStatus::Placed, OrderStatus::ReadyForPickup],
            ],

            // Rider owns the courier hops in the canonical flow:
            // ReadyForPickup → PickedUp → AtSortingCenter → Sorted → AssignedToRider → OutForDelivery
            // (The rider performs the pickup and delivery legs; sorting is Logistics.)
            self::Rider => [
                [OrderStatus::ReadyForPickup, OrderStatus::PickedUp],
                [OrderStatus::PickedUp, OrderStatus::AtSortingCenter],
                [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery],
                [OrderStatus::OutForDelivery, OrderStatus::Delivered],
                [OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed],
            ],

            // Logistics sorts parcels and assigns riders, and owns the
            // after-failure recovery: reschedule (re-assign) or return.
            self::Logistics => [
                [OrderStatus::AtSortingCenter, OrderStatus::Sorted],
                [OrderStatus::Sorted, OrderStatus::AssignedToRider],
                [OrderStatus::DeliveryFailed, OrderStatus::AssignedToRider],
                [OrderStatus::DeliveryFailed, OrderStatus::Returned],
            ],

            // Buyer completes the loop by confirming receipt.
            self::Buyer => [
                [OrderStatus::Delivered, OrderStatus::Completed],
            ],

            // Automatic system steps (courier assignment).
            // The System actor no longer assigns directly from ReadyForPickup;
            // assignment now happens via CourierAssignmentService with the
            // proper actor (Rider for pickup, Logistics for delivery).
            self::System => [],
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
