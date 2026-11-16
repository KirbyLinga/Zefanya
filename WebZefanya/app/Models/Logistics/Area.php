<?php

namespace App\Models\Logistics;

use App\Models\Rider\Courier;
use Database\Factories\Logistics\AreaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery area owned by one logistics provider. Riders (couriers) are
 * linked to the area they serve, and a parcel records the area it is bound
 * for so the sorting center can assign the right rider.
 */
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    protected $fillable = [
        'logistics_provider_id',
        'station_hub_id',
        'name',
        'municipality',
        'city_municipality_code',
        'barangay_code',
        'barangay_name',
        'coverage_validated_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'coverage_validated_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'station_hub_id');
    }

    /**
     * Coverage that can actually route a parcel: PSGC-validated, active, and
     * attached to an approved, active Station Hub under an approved, active
     * Province Hub of the same provider.
     */
    public function scopeOperationalCoverage(Builder $query): Builder
    {
        return $query
            ->whereNotNull('barangay_code')
            ->whereNotNull('coverage_validated_at')
            ->where('is_active', true)
            ->whereHas('station', fn (Builder $station) => $station
                ->whereColumn('logistics_provider_id', 'areas.logistics_provider_id')
                ->where('hub_type', LogisticsHub::STATION)
                ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
                ->where('is_active', true))
            ->whereHas('station.parent', fn (Builder $parent) => $parent
                ->whereColumn('logistics_provider_id', 'areas.logistics_provider_id')
                ->where('hub_type', LogisticsHub::PROVINCE)
                ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
                ->where('is_active', true));
    }

    public function couriers(): BelongsToMany|HasMany
    {
        if (! Schema::hasTable('area_courier')) {
            return $this->hasMany(Courier::class);
        }

        return $this->belongsToMany(Courier::class, 'area_courier');
    }
}
