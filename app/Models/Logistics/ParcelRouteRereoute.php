<?php

namespace App\Models\Logistics;

use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for route changes.
 *
 * Records when a planned route is superseded by a new route due to
 * connection unavailability, hub inactivity, or manual request. The route row
 * is updated in place, so the previous and new paths are frozen here.
 */
class ParcelRouteRereoute extends Model
{
    use HasFactory;

    protected $table = 'parcel_route_rereoutes';

    protected $fillable = [
        'seller_order_id',
        'original_route_id',
        'new_route_id',
        'reason',
        'triggered_by',
        'failed_connection',
        'previous_path',
        'new_path',
        'completed_legs',
        'authorized_by_type',
        'authorized_by_id',
        'incident_id',
        'rerouted_at',
    ];

    protected function casts(): array
    {
        return [
            'failed_connection' => 'json',
            'previous_path' => 'json',
            'new_path' => 'json',
            'completed_legs' => 'json',
            'authorized_by_id' => 'integer',
            'incident_id' => 'integer',
            'rerouted_at' => 'datetime',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incidents::class, 'incident_id');
    }

    public function originalRoute(): BelongsTo
    {
        return $this->belongsTo(ParcelRoute::class, 'original_route_id');
    }

    public function newRoute(): BelongsTo
    {
        return $this->belongsTo(ParcelRoute::class, 'new_route_id');
    }
}
