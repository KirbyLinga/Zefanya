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
    case Cancelled = 'RETURN_CANCELLED';
    case Lost = 'RETURN_LOST';
    case Damaged = 'RETURN_DAMAGED';
    case ReceiptDisputed = 'RETURN_RECEIPT_DISPUTED';
    case Closed = 'RETURN_CLOSED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Rejected, self::Cancelled, self::Lost, self::Damaged, self::Closed => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::InDispute => 'In Dispute',
            default => str_replace('_', ' ', $this->value),
        };
    }
}
