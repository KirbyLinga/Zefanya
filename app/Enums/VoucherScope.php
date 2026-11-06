<?php

namespace App\Enums;

/**
 * Voucher stacking slots.
 *
 * A cart can hold at most one platform voucher, one shipping voucher, and one
 * shop voucher per selected store. Stored as VARCHAR in `vouchers.scope` and
 * cast by the Voucher model, matching the project's enum convention.
 */
enum VoucherScope: string
{
    /** Discounts the whole merchandise subtotal after shop vouchers. */
    case Platform = 'platform';

    /** Discounts one Seller's merchandise subtotal only. */
    case Shop = 'shop';

    /** Discounts eligible shipping fees only. */
    case Shipping = 'shipping';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform voucher',
            self::Shop => 'Shop voucher',
            self::Shipping => 'Shipping voucher',
        };
    }
}
