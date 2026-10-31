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

    public function label(): string
    {
        return match ($this) {
            self::InDispute => 'In Dispute',
            default => str_replace('_', ' ', $this->value),
        };
    }
}
