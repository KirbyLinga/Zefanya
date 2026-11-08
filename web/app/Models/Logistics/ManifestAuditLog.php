<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use LogicException;

/**
 * Append-only audit trail for manifest lifecycle events.
 * Captures who, when, how (IP/user agent), and what action was taken.
 */
class ManifestAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'manifest_id',
        'manifest_item_id',
        'action',
        'actor_type',
        'actor_id',
        'actor_name',
        'ip_address',
        'user_agent',
        'verification_method',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Manifest audit logs cannot be edited.');
        });

        static::deleting(function (): never {
            throw new LogicException('Manifest audit logs cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['schedule_change' => 'array'];
    }

    /**
     * Bulk queries skip model events, so they are refused here as well.
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Manifest audit logs cannot be edited.');
            }

            public function delete()
            {
                throw new LogicException('Manifest audit logs cannot be deleted.');
            }

            public function forceDelete()
            {
                throw new LogicException('Manifest audit logs cannot be deleted.');
            }
        };
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ManifestItem::class, 'manifest_item_id');
    }

    /**
     * Record a manifest lifecycle event in the audit trail.
     * Call inside transaction so log and action commit together.
     */
    public static function record(
        LogisticsProvider $actor,
        string $action,
        Manifest $manifest,
        ?ManifestItem $item,
        Request $request,
        string $verificationMethod = 'manual',
    ): self {
        $log = new self;
        $log->forceFill([
            'manifest_id' => $manifest->id,
            'manifest_item_id' => $item?->id,
            'action' => $action,
            'actor_type' => 'logistics',
            'actor_id' => $actor->id,
            'actor_name' => $actor->business_name ?: $actor->fullName(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'verification_method' => $verificationMethod,
        ])->save();

        return $log;
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'dispatched' => 'Manifest Dispatched',
            'received' => 'Parcel Received',
            'receiving_closed' => 'Receiving closed with missing parcels',
            'courier_decline' => 'Courier declined trip',
            'courier_delay' => 'Courier reported a delay',
            'courier_schedule' => 'Planned departure changed',
            'cancelled' => 'Manifest Cancelled',
            'deleted' => 'Manifest Deleted',
            default => ucfirst($this->action),
        };
    }
}
