<?php

namespace App\Models\Logistics;

use App\Models\Courier;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'name',
        'municipality',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(Courier::class);
    }
}
