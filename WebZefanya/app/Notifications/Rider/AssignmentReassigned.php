<?php

namespace App\Notifications\Rider;

use App\Models\Shared\SellerOrder;

/**
 * A pickup or delivery task was taken away from this rider and given to
 * somebody else. The parcel disappears from this rider's actionable list
 * because the assignment column now points at the new courier.
 */
class AssignmentReassigned extends RiderNotification
{
    /** @param 'pickup'|'delivery' $role */
    public function __construct(
        public SellerOrder $sellerOrder,
        public string $role,
        public int $newAssignmentId,
    ) {}

    public function dedupeKey(): string
    {
        return 'assignment-reassigned:'.$this->role.':'.$this->newAssignmentId;
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        $pickup = $this->role === 'pickup';

        return [
            'type' => 'rider_assignment_reassigned',
            'title' => $pickup ? 'Pickup reassigned' : 'Delivery reassigned',
            'message' => $this->parcelReference($this->sellerOrder).' is no longer assigned to you. It will not appear in your task list.',
            'url' => $pickup ? route('rider.pickups.index') : route('rider.deliveries.index'),
            'seller_order_id' => $this->sellerOrder->id,
            'tracking_number' => $this->sellerOrder->tracking_number,
        ];
    }
}
