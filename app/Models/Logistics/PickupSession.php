<?php

namespace App\Models\Logistics;

use App\Models\Rider\Courier;
use App\Models\Rider\RiderEarning;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rider's visit to one seller inside one pickup window. Pickup pay is worked
 * out per session: the first parcel collected pays the first rate, every other
 * one the additional rate. Parcels still change status one at a time, each with
 * its own verified scan.
 */
class PickupSession extends Model
{
    public const OPEN = 'OPEN';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const COMPLETED = 'COMPLETED';

    public const MISSED = 'MISSED';

    public const CANCELLED = 'CANCELLED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'window_starts_at' => 'datetime',
            'window_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_minor' => 'integer',
            'additional_minor' => 'integer',
            'first_payable' => 'boolean',
        ];
    }

    /** New parcels can still join; once the first one is collected the members are fixed. */
    public function acceptsParcels(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::OPEN, self::IN_PROGRESS], true);
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(SellerOrder::class, 'pickup_session_id');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(RiderEarning::class, 'pickup_session_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }
}
