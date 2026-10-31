<?php

namespace App\Models\Logistics;

use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof-of-delivery evidence for one parcel.
 *
 * The photo lives on the private `local` disk and is only streamed through an
 * authorized controller action — never exposed as a public URL.
 */
class DeliveryProof extends Model
{
    protected $fillable = [
        'seller_order_id',
        'courier_id',
        'rider_id',
        'photo_path',
        'photo_mime',
        'photo_size',
        'received_by',
        'notes',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'photo_size' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    /**
     * The Logistics company that handled the parcel (rider's employer).
     */
    public function provider(): ?LogisticsProvider
    {
        return $this->courier?->provider;
    }
}
