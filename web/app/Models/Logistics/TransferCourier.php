<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransferCourier extends Model
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function account(): HasOne
    {
        return $this->hasOne(CourierAccount::class);
    }
}
