<?php

namespace App\Models\Logistics;

use App\Models\Rider\Courier;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a courier being assigned to a parcel (pickup or
 * delivery), so the full assignment trail — including re-assignments — is
 * auditable.
 */
class DeliveryAssignment extends Model
{
    protected $fillable = [
        'seller_order_id',
        'courier_id',
        'role',
        'assigned_by_type',
        'assigned_by_id',
        'notes',
    ];

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
