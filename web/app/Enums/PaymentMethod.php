<?php

namespace App\Enums;

/**
 * Payment methods for the `payments.method` column (VARCHAR + this enum cast).
 *
 * Checkout creates COD payments. Existing prepaid records are recognized for
 * cancellation/refund accounting without enabling a new checkout processor.
 */
enum PaymentMethod: string
{
    case Cod = 'cod';
    // Recognize existing prepaid records; checkout remains COD-only.
    case Ewallet = 'ewallet';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash on Delivery (COD)',
            self::Ewallet => 'E-wallet',
            self::Card => 'Card',
        };
    }
}
