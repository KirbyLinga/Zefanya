<?php

namespace App\Models\Shared;

use App\Enums\ParcelIncidentStatus;
use App\Models\Rider\Rider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per reported lost/damaged parcel. The parcel is a `SellerOrder`, so
 * `incident_seller_order_id` references that fulfillment unit directly.
 *
 * Status is a VARCHAR cast to `ParcelIncidentStatus`. It is NEVER mass-assigned
 * from request data — the service writes it through controlled transitions.
 */
class Incidents extends Model
{
    use HasFactory;

    public const TABLE = 'incidents';

    protected $attributes = ['status' => 'open'];

    protected $hidden = ['photo_disk', 'photo_path'];

    protected $fillable = [
        'incident_seller_order_id',
        'reporter_id',
        'reporter_type',
        'type',
        'description',
        'reason',
        'custody_snapshot',
        'photo_disk',
        'photo_path',
        'happened_at',
        'status',
    ];

    protected $casts = [
        'incident_seller_order_id' => 'integer',
        'reporter_id' => 'integer',
        'type' => 'string',
        'description' => 'string',
        'happened_at' => 'datetime',
        'status' => ParcelIncidentStatus::class,
        'custody_snapshot' => 'array',
    ];

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'incident_seller_order_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Rider::class, 'reporter_id')->where('reporter_type', 'rider');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvents::class, 'incident_id')->orderBy('id');
    }

    public function eventCounts(): array
    {
        return $this->events()
            ->selectRaw('event, count(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event')
            ->toArray();
    }

    public function isOpen(): bool
    {
        return $this->status === ParcelIncidentStatus::Open;
    }
}
