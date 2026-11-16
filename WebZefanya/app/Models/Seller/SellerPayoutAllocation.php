<?php

namespace App\Models\Seller;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPayoutAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(SellerPayoutRequest::class, 'seller_payout_request_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlement::class, 'seller_settlement_id');
    }
}
