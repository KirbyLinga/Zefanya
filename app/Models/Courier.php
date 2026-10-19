<?php

namespace App\Models;

use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Courier extends Model
{
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
}
