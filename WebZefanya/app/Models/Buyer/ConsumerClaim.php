<?php

namespace App\Models\Buyer;

use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumerClaim extends Model
{
    protected $guarded = ['*'];

    protected $attributes = ['status' => 'submitted', 'revision' => 0];

    public const CLOSED_STATUSES = ['closed'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ConsumerClaimUpdate::class)->orderBy('id');
    }
}
