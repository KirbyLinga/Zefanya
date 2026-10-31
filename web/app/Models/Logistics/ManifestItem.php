<?php

namespace App\Models\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parcel on a manifest. Receipt is recorded per item, so a destination
 * hub can receive a manifest partially.
 */
class ManifestItem extends Model
{
    protected $fillable = [
        'manifest_id',
        'seller_order_id',
        'tracking_number',
        'received_at',
        'received_by_type',
        'received_by_id',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Preserve the label already issued for the parcel.
        static::creating(function (self $item): void {
            if ($item->tracking_number === null && $item->seller_order_id !== null) {
                $item->tracking_number = SellerOrder::query()
                    ->whereKey($item->seller_order_id)
                    ->value('tracking_number');
            }
        });
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    /**
     * An active item is still part of an open manifest. Releasing it keeps the
     * historical row but frees the parcel for a later manifest.
     */
    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * Human-readable custody state for the Logistics movement history.
     * Dispatch records in-transit custody; only a receiving scan transfers it.
     */
    public function custodyLabel(): string
    {
        if ($this->received_at !== null) {
            return 'Received at destination';
        }

        if ($this->released_at !== null) {
            return 'Removed from manifest';
        }

        return $this->manifest?->status === ManifestStatus::Dispatched
            ? 'In transit — origin hub custody'
            : 'At origin hub';
    }

    /**
     * Record receipt of this parcel and release its active membership so the
     * same parcel can be manifested again in a future transfer.
     */
    public function markReceived(string $actorType, ?int $actorId = null): void
    {
        $now = now();

        $this->forceFill([
            'received_at' => $this->received_at ?? $now,
            'received_by_type' => $actorType,
            'received_by_id' => $actorId,
            'released_at' => $this->released_at ?? $now,
        ])->save();
    }

    /**
     * Release an unreceived item (for example when its manifest is cancelled)
     * without deleting the audit row.
     */
    public function release(): void
    {
        if ($this->released_at !== null) {
            return;
        }

        $this->forceFill(['released_at' => now()])->save();
    }
}
