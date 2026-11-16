<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Courier: transport personnel a Logistics company records for moving
 * parcels between its stations, hubs and partner companies. A record only,
 * not a user account, and never paid through Zefanya. Not the same as
 * App\Models\Rider\Courier, which is a Rider's job profile.
 */
class TransferCourier extends Model
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    protected $guarded = ['*'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TransferCourierAssignment::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function label(): string
    {
        return $this->name.' · '.$this->vehicle_type.($this->plate_number ? ' · '.$this->plate_number : '');
    }
}
