<?php

namespace App\Models\Seller;

use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a Seller owes back after being paid for an order that was then returned or refunded. */
class SellerDebt extends Model
{
    public const AFTER_PAYOUT_RETURN = 'return_after_payout';

    /** Refunded shipping and the return trip, more than the order's own earnings could cover. */
    public const RETURN_CHARGES = 'return_charges';

    /** A failed delivery sent back to the Seller: the attempt and the trip back. No settlement exists. */
    public const FAILED_DELIVERY = 'failed_delivery';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'settled_minor' => 'integer'];
    }

    public function outstandingMinor(): int
    {
        return max(0, (int) $this->amount_minor - (int) $this->settled_minor);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereColumn('settled_minor', '<', 'amount_minor');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlement::class, 'seller_settlement_id');
    }
}
