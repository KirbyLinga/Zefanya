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
        'issue_type',
        'issue_note',
        'issue_recorded_at',
        'verified_for_dispatch_at',
        'verified_for_dispatch_by_type',
        'verified_for_dispatch_by_id',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'released_at' => 'datetime',
            'issue_recorded_at' => 'datetime',
            'verified_for_dispatch_at' => 'datetime',
            'recovered_at' => 'datetime',
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

    public function recoveredHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'recovered_hub_id');
    }

    /**
     * The parcel left its hub on a transfer that was later cancelled, and no
     * hub has verified where it is since. It stays under exception until a
     * recovery scan records its physical location.
     */
    public function awaitsRecovery(): bool
    {
        $manifest = $this->manifest;

        return $manifest !== null
            && $manifest->status === ManifestStatus::Cancelled
            && $manifest->dispatched_at !== null
            && $this->received_at === null
            && $this->recovered_at === null;
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

        if ($this->recovered_at !== null) {
            return 'Recovered at '.($this->recoveredHub?->name ?? 'a hub');
        }

        if ($this->awaitsRecovery()) {
            return 'Awaiting recovery scan';
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

        $this->forceFill([
            'released_at' => now(),
            'verified_for_dispatch_at' => null,
            'verified_for_dispatch_by_type' => null,
            'verified_for_dispatch_by_id' => null,
        ])->save();
    }

    /**
     * Record a parcel as damaged: receive it into custody with the issue flagged.
     */
    public function markDamaged(string $note, string $actorType, ?int $actorId = null): void
    {
        $now = now();
        $this->forceFill([
            'received_at' => $this->received_at ?? $now,
            'received_by_type' => $actorType,
            'received_by_id' => $actorId,
            'released_at' => $this->released_at ?? $now,
            'issue_type' => 'damaged',
            'issue_note' => $note,
            'issue_recorded_at' => $now,
        ])->save();
    }

    /**
     * Record a parcel as missing: release it without receiving, keep custody at origin.
     */
    public function markMissing(string $note): void
    {
        $now = now();
        $this->forceFill([
            'released_at' => $this->released_at ?? $now,
            'issue_type' => 'missing',
            'issue_note' => $note,
            'issue_recorded_at' => $now,
        ])->save();
    }

    /**
     * Check if this item has an issue (missing or damaged).
     */
    public function hasIssue(): bool
    {
        return $this->issue_type !== null;
    }

    /**
     * Label for issue display.
     */
    public function issueLabel(): string
    {
        return match ($this->issue_type) {
            'missing' => 'Missing',
            'damaged' => 'Damaged',
            default => '',
        };
    }

    /**
     * Record dispatch verification — persistent, not time-limited.
     */
    public function markVerifiedForDispatch(string $actorType, ?int $actorId = null): void
    {
        $this->forceFill([
            'verified_for_dispatch_at' => now(),
            'verified_for_dispatch_by_type' => $actorType,
            'verified_for_dispatch_by_id' => $actorId,
        ])->save();
    }

    /**
     * Check if dispatch verification exists and is valid.
     */
    public function isVerifiedForDispatch(): bool
    {
        return $this->verified_for_dispatch_at !== null;
    }

    /**
     * Clear dispatch verification (when parcel is removed or manifest is cancelled).
     */
    public function clearDispatchVerification(): void
    {
        $this->forceFill([
            'verified_for_dispatch_at' => null,
            'verified_for_dispatch_by_type' => null,
            'verified_for_dispatch_by_id' => null,
        ])->save();
    }
}
