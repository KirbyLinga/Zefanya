<?php

namespace App\Enums;

enum ParcelReturnStatus: string
{
    case Requested = 'RETURN_REQUESTED';
    case InTransit = 'RETURN_IN_TRANSIT';
    case AtOrigin = 'RETURN_AT_ORIGIN';
    case AwaitingSeller = 'AWAITING_SELLER_RECEIPT';
    case Completed = 'RETURNED_TO_SELLER';
    case Rejected = 'RETURN_REJECTED';
    case InDispute = 'RETURN_IN_DISPUTE';
    /** The Seller reported a problem at physical handover. Custody stays with Logistics until Admin decides. */
    case ReceiptDisputed = 'RETURN_RECEIPT_DISPUTED';
    /** Closed before a rider collected it: the buyer cancelled or Admin closed a no-show. The buyer keeps the item. */
    case Cancelled = 'RETURN_CANCELLED';
    /** Admin closed a collected return whose Seller receipt was disputed. Money stays held for Admin review. */
    case Closed = 'RETURN_CLOSED';
    /** Admin confirmed the parcel lost in the return network. */
    case Lost = 'RETURN_LOST';
    /** Admin confirmed the parcel damaged beyond return in the return network. */
    case Damaged = 'RETURN_DAMAGED';

    public function label(): string
    {
        return match ($this) {
            self::InDispute => 'In Dispute',
            self::ReceiptDisputed => 'Seller receipt disputed',
            self::Cancelled => 'Return cancelled',
            self::Closed => 'Return closed by Admin',
            self::Lost => 'Lost during return',
            self::Damaged => 'Damaged during return',
            default => str_replace('_', ' ', $this->value),
        };
    }

    /** Statuses after which nothing moves physically and no workflow step remains. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled, self::Closed, self::Lost, self::Damaged], true);
    }
}
