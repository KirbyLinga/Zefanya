<?php

namespace App\Models\Logistics;

use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupAttempt extends Model
{
    /** The table records an explicit failed_at and has no created/updated columns. */
    public $timestamps = false;

    protected $fillable = [
        'seller_order_id',
        'attempt_number',
        'rider_id',
        'failure_reason',
        'failed_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'failed_at' => 'datetime',
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
