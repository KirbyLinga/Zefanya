<?php

namespace App\Models\Shared;

use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelIncidentType;
use App\Models\Rider\Rider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per reported parcel exception. The parcel is a `SellerOrder`, so
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
        'blocked_leg_index',
    ];

    protected $casts = [
        'incident_seller_order_id' => 'integer',
        'reporter_id' => 'integer',
        'type' => 'string',
        'description' => 'string',
        'happened_at' => 'datetime',
        'status' => ParcelIncidentStatus::class,
        'custody_snapshot' => 'array',
        'blocked_leg_index' => 'integer',
        'resolution_context' => 'array',
        'reviewed_at' => 'datetime',
        'reviewed_by_id' => 'integer',
        'resolved_at' => 'datetime',
        'resolved_by_id' => 'integer',
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
            ->reorder()
            ->selectRaw('event, count(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event')
            ->toArray();
    }

    public function isActive(): bool
    {
        if ($this->isHeld()) {
            return true;
        }

        return $this->status instanceof ParcelIncidentStatus
            ? $this->status->isActive()
            : in_array($this->status, [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value], true);
    }

    /**
     * Backwards-compatible alias used by existing admin views/policies.
     * "Open" here means the exception still blocks normal movement.
     */
    public function isOpen(): bool
    {
        return $this->isActive();
    }

    public function isUnderReview(): bool
    {
        return $this->status === ParcelIncidentStatus::UnderReview;
    }

    public function isHeld(): bool
    {
        return strtoupper((string) $this->resolution) === 'KEEP_ON_HOLD';
    }

    public function typeLabel(): string
    {
        return ParcelIncidentType::tryFrom(strtolower((string) $this->type))?->label()
            ?? ucfirst((string) $this->type);
    }
}
