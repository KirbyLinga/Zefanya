<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Courier extends Model
{
    /** Legacy workload display cap; weight is the real assignment limit. */
    public const ACTIVE_CAPACITY = 1;

    protected static function booted(): void
    {
        // Keep callers that still write the legacy single-area field compatible.
        static::saved(function (Courier $courier): void {
            if (! Schema::hasTable('area_courier')) {
                return;
            }
            if (! $courier->wasChanged('area_id') && ! $courier->wasRecentlyCreated) {
                return;
            }
            $previous = $courier->getRawOriginal('area_id');
            if ($previous !== null && (int) $previous !== (int) $courier->area_id) {
                $courier->areas()->detach($previous);
            }
            if ($courier->area_id !== null) {
                $courier->areas()->syncWithoutDetaching([$courier->area_id]);
            }
        });
    }

    public function areas(): BelongsToMany|HasMany
    {
        if (! Schema::hasTable('area_courier')) {
            return $this->hasMany(Area::class, 'id', 'area_id');
        }

        return $this->belongsToMany(Area::class, 'area_courier');
    }

    public function activePickups(): HasMany
    {
        return $this->hasMany(SellerOrder::class, 'courier_id')
            ->whereIn('status', [OrderStatus::ReadyForPickup, OrderStatus::PickedUp]);
    }

    public function activeDeliveries(): HasMany
    {
        return $this->hasMany(SellerOrder::class, 'delivery_courier_id')
            ->whereIn('status', [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery]);
    }

    public function scopeWithWorkload(Builder $query): Builder
    {
        return $query->withCount(['activePickups', 'activeDeliveries']);
    }

    /**
     * Add the Rider's current active parcel weight as one SQL aggregate. The
     * alias can be read for every candidate without loading the parcels.
     */
    public function scopeWithActiveWeight(Builder $query): Builder
    {
        return $query->addSelect([
            'active_parcel_weight_grams' => SellerOrder::query()
                ->selectRaw('COALESCE(SUM(seller_orders.parcel_weight_grams), 0)')
                ->where(function (Builder $parcel): void {
                    $parcel->where(function (Builder $pickup): void {
                        $pickup->whereColumn('seller_orders.courier_id', 'couriers.id')
                            ->whereIn('seller_orders.status', [
                                OrderStatus::ReadyForPickup,
                                OrderStatus::PickedUp,
                            ]);
                    })->orWhere(function (Builder $delivery): void {
                        $delivery->whereColumn('seller_orders.delivery_courier_id', 'couriers.id')
                            ->whereIn('seller_orders.status', [
                                OrderStatus::Sorted,
                                OrderStatus::AssignedToRider,
                                OrderStatus::OutForDelivery,
                            ]);
                    });
                }),
        ]);
    }

    public function activeWorkload(): int
    {
        return (int) ($this->active_pickups_count ?? $this->activePickups()->count())
            + (int) ($this->active_deliveries_count ?? $this->activeDeliveries()->count());
    }

    /**
     * Total weight (g) the Rider is already carrying across active legs, in a
     * single aggregate query over the frozen parcel weights.
     */
    public function activeWeightGrams(): int
    {
        if (array_key_exists('active_parcel_weight_grams', $this->getAttributes())) {
            return (int) $this->getAttributes()['active_parcel_weight_grams'];
        }

        return (int) SellerOrder::query()
            ->where(function (Builder $parcel): void {
                $parcel->where(function (Builder $pickup): void {
                    $pickup->where('courier_id', $this->getKey())
                        ->whereIn('status', [OrderStatus::ReadyForPickup, OrderStatus::PickedUp]);
                })->orWhere(function (Builder $delivery): void {
                    $delivery->where('delivery_courier_id', $this->getKey())
                        ->whereIn('status', [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery]);
                });
            })
            ->sum('parcel_weight_grams');
    }

    /** True when adding this parcel keeps the Rider at or below their limit. */
    public function hasCapacityFor(int $parcelWeightGrams): bool
    {
        return $this->activeWeightGrams() + $parcelWeightGrams <= (int) $this->max_weight_grams;
    }

    /** @use HasFactory<CourierFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected $casts = [
        'service_area' => 'array',
        'is_available' => 'boolean',
        'max_weight_grams' => 'integer',
    ];

    protected $fillable = [
        'logistics_provider_id',
        'rider_id',
        'area_id',
        'name',
        'phone',
        'service_area',
        'is_available',
        'vehicle_type',
        'max_weight_grams',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    /**
     * True when this courier's service_area contains the given municipality name.
     */
    public function servesMunicipality(string $municipality): bool
    {
        return in_array($municipality, $this->service_area, true);
    }

    /**
     * A fleet record is assignable only when it represents an eligible Rider
     * in an approved, active station of the same Logistics company.
     */
    public function isEligibleForAssignment(): bool
    {
        $rider = $this->relationLoaded('rider') ? $this->rider : $this->rider()->first();

        return $rider !== null
            && (int) $rider->logistics_provider_id === (int) $this->logistics_provider_id
            && $rider->isEligibleForAssignment();
    }

    public function scopeEligibleForAssignment(Builder $query): Builder
    {
        return $query
            ->whereNotNull('couriers.rider_id')
            ->whereHas('rider', function (Builder $rider): void {
                $rider->whereIn('status', Rider::ASSIGNABLE_STATUSES)
                    ->whereNotNull('email_verified_at')
                    ->whereNotNull('station_hub_id')
                    ->whereColumn('riders.logistics_provider_id', 'couriers.logistics_provider_id')
                    ->whereHas('station', function (Builder $station): void {
                        $station->where('hub_type', LogisticsHub::STATION)
                            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
                            ->where('is_active', true)
                            ->whereColumn('logistics_hubs.logistics_provider_id', 'couriers.logistics_provider_id');
                    });
            });
    }
}
