<?php

namespace App\Models\Shared;

use App\Models\Buyer\Buyer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One voucher in one buyer's wallet. */
class VoucherClaim extends Model
{
    protected $fillable = ['voucher_id', 'buyer_id'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }
}
