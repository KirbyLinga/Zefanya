<?php

namespace App\Models\Buyer;

use App\Models\Seller\Seller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreFollow extends Model
{
    protected $fillable = ['buyer_id', 'seller_id'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
