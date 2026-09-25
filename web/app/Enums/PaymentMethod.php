<?php

namespace App\Enums;

/**
 * Payment methods for the `payments.method` column. COD is the only method
 * for now; new methods extend the migration enum and this enum together.
 */
enum PaymentMethod: string
{
    case Cod = 'cod';
}
