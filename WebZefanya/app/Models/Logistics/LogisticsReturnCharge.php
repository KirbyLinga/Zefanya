<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money between Zefanya and a Logistics company caused by one return. */
class LogisticsReturnCharge extends Model
{
    /** The company repays Zefanya (damaged in transit). */
    public const TO_ZEFANYA = 'to_zefanya';

    /** Zefanya pays the company (return trip, failed delivery work). */
    public const TO_PROVIDER = 'to_provider';

    public const TRANSIT_DAMAGE_REFUND = 'transit_damage_refund';

    public const RETURN_TRIP = 'return_trip';

    public const FAILED_DELIVERY = 'failed_delivery';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'settled_at' => 'datetime'];
    }

    public function owedToZefanya(): bool
    {
        return $this->direction === self::TO_ZEFANYA;
    }

    public function label(): string
    {
        return match ($this->reason) {
            self::TRANSIT_DAMAGE_REFUND => 'Refund for a parcel damaged in transit',
            self::RETURN_TRIP => 'Return trip from the buyer to the Seller',
            self::FAILED_DELIVERY => 'Failed delivery attempt and trip back to the Seller',
            default => $this->reason,
        };
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function parcelReturn(): BelongsTo
    {
        return $this->belongsTo(ParcelReturn::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'settled_by_admin_id');
    }
}
