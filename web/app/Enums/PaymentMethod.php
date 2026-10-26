<?php

namespace App\Enums;

/**
 * Payment methods for the `payments.method` column (VARCHAR + this enum cast).
 *
 * COD is the only INTEGRATED method: cash is collected at the door and the
 * payment is settled by SellerOrderStatusService when the parcel is Delivered.
 * The remaining methods are selectable at checkout but are recorded as
 * PENDING / not-integrated until a real gateway is wired up, so the flow NEVER
 * blocks on an unimplemented payment provider.
 */
enum PaymentMethod: string
{
    case Cod = 'cod';
    case Gcash = 'gcash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash on Delivery (COD)',
            self::Gcash => 'GCash',
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Credit / debit card',
        };
    }

    /**
     * True when the platform can actually take payment this way today.
     * Non-integrated methods are stored as Pending and clearly labelled.
     */
    public function isIntegrated(): bool
    {
        return $this === self::Cod;
    }
}
