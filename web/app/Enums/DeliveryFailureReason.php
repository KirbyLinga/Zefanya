<?php

namespace App\Enums;

enum DeliveryFailureReason: string
{
    case RecipientUnavailable = 'recipient_unavailable';
    case WrongAddress = 'wrong_address';
    case RefusedByRecipient = 'refused_by_recipient';
    case UnreachableByPhone = 'unreachable_by_phone';
    case DamagedParcel = 'damaged_parcel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RecipientUnavailable => 'Recipient unavailable',
            self::WrongAddress => 'Wrong/incomplete address',
            self::RefusedByRecipient => 'Refused by recipient',
            self::UnreachableByPhone => 'Unreachable by phone',
            self::DamagedParcel => 'Damaged parcel',
            self::Other => 'Other',
        };
    }
}
