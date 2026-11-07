<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

class PaymentCancellationRefund extends Model
{
    /** Refund liability only; a pending row does not mean money was sent. */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
