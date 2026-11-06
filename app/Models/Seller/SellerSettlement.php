<?php

namespace App\Models\Seller;

use App\Models\Logistics\CodRemittance;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerSettlement extends Model
{
    public const PENDING_PAYOUT = 'PENDING_PAYOUT';

    public const PAID = 'PAID';

    /** Approved or refunded return money has left the pending payout. */
    public const RELEASED = 'RELEASED';

    public const COMMISSION_FINALIZATION_MISSING = 'commission_finalization_missing';

    public const VOUCHER_FUNDING_UNKNOWN = 'voucher_funding_unknown';

    public const NEGATIVE_SELLER_NET = 'negative_seller_net';

    public const FINANCIAL_RECONCILIATION_REQUIRED = 'financial_reconciliation_required';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'collected_amount_minor' => 'integer',
            'shipping_allocation_minor' => 'integer',
            'seller_funded_discount_minor' => 'integer',
            'marketplace_funded_discount_minor' => 'integer',
            'commission_bps' => 'integer',
            'commission_minor' => 'integer',
            'seller_payout_minor' => 'integer',
            'paid_amount_minor' => 'integer',
            'financials_finalized_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** A payout request can pay part of a settlement, so this is what is still owed. */
    public function remainingPayoutMinor(): int
    {
        return max(0, (int) $this->seller_payout_minor - (int) $this->paid_amount_minor);
    }

    public function codRemittance(): BelongsTo
    {
        return $this->belongsTo(CodRemittance::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
