<?php

namespace App\Enums;

/**
 * Payment methods for the `payments.method` column (VARCHAR + this enum cast).
 *
 * Cash on Delivery is the only payment method: cash is collected at the door and the
 * payment is settled by SellerOrderStatusService when the parcel is Delivered.
 */
enum PaymentMethod: string
{
    case Cod = 'cod';

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash on Delivery (COD)',
        };
    }
}
