<?php

namespace App\Models\Rider;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pay a Courier earned for one successful job. Failed jobs earn nothing and
 * have no row. Pending until a RiderPayout covers it.
 */
class RiderEarning extends Model
{
    public const PICKUP = 'pickup';

    public const DELIVERY = 'delivery';

    /** Centavos per successful job. */
    public const RATES = [
        self::PICKUP => 3000,
        self::DELIVERY => 5000,
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'earned_at' => 'datetime',
        ];
    }

    public function isPaid(): bool
    {
        return $this->rider_payout_id !== null;
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(RiderPayout::class, 'rider_payout_id');
    }
}
