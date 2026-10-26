<?php

namespace App\Models\Logistics;

use App\Enums\ManifestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hub-to-hub parcel transfer. Status moves DRAFT → DISPATCHED → RECEIVED,
 * or to CANCELLED before receipt; the row is retained as an audit record.
 */
class Manifest extends Model
{
    /**
     * `status` is deliberately NOT mass-assignable — lifecycle transitions are
     * written explicitly (forceFill / a future service), never request data.
     */
    protected $fillable = [
        'logistics_provider_id',
        'origin_hub_id',
        'destination_hub_id',
        'dispatched_at',
        'dispatched_by_type',
        'dispatched_by_id',
        'received_at',
        'received_by_type',
        'received_by_id',
    ];

    protected $attributes = [
        'status' => 'DRAFT', 'direction' => 'forward',
    ];

    protected function casts(): array
    {
        return [
            'status' => ManifestStatus::class,
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function originHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_hub_id');
    }

    public function destinationHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'destination_hub_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ManifestItem::class);
    }

    public function isOpen(): bool
    {
        return $this->status?->isOpen() ?? false;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ManifestStatus::Draft->value,
            ManifestStatus::Dispatched->value,
        ]);
    }

    public function scopeStatus(Builder $query, ManifestStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
