<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupAttempt extends Model
{
    protected $fillable = [
        'seller_order_id',
        'attempt_number',
        'rider_id',
        'failure_reason',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
    ];

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}
