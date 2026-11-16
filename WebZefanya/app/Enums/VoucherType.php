<?php

namespace App\Enums;

/**
 * Voucher discount kinds.
 *
 * Stored as VARCHAR in `vouchers.type` and cast by the Voucher model, so the
 * permitted values live in application code rather than in a native MySQL ENUM
 * (project rule #6).
 */
enum VoucherType: string
{
    /** A flat amount off, stored in centavos. */
    case Fixed = 'fixed';

    /** A percentage off the subtotal, 0-100. */
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::Percent => 'Percentage',
        };
    }
}
