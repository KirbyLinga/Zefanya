<?php

namespace App\Enums;

/**
 * Payment lifecycle status for the `payments.status` column
 * (VARCHAR + this enum cast — MySQL ENUM is deprecated project-wide).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';

    case Paid = 'paid';
}
