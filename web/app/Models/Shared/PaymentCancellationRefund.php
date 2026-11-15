<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

class PaymentCancellationRefund extends Model
{
    protected $fillable = ['payment_id', 'seller_order_id', 'buyer_id', 'amount_minor', 'reason', 'status'];

    /** Refund liability only; a pending row does not mean money was sent. */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
