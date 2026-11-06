<?php

namespace App\Enums;

/**
 * Store sale discount kinds.
 *
 * Stored as VARCHAR in `store_sales.type` and cast by the StoreSale model, so
 * the permitted values live in application code rather than in a native MySQL
 * ENUM (project rule #6).
 */
enum StoreSaleType: string
{
    /** A whole percentage off the regular price, 1-99. */
    case Percent = 'percent';

    /** A set sale price, stored in centavos. */
    case FixedPrice = 'fixed_price';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage',
            self::FixedPrice => 'Fixed price',
        };
    }
}
