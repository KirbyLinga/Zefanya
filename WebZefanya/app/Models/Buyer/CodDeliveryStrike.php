<?php

namespace App\Models\Buyer;

use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One COD parcel whose delivery finally failed because of the buyer. Written by CodRiskService only. */
class CodDeliveryStrike extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
