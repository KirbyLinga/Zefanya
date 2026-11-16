<?php

namespace App\Models\Shared;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for every seller_order status transition.
 * All pipeline changes MUST flow through SellerOrderStatusService, which
 * calls StatusHistory::record() so the log stays complete.
 */
class StatusHistory extends Model
{
    protected $fillable = [
        'seller_order_id',
        'from_status',
        'to_status',
        'changed_by',
        'note',
        'actor_type',
        'actor_id',
        'source',
    ];

    protected $casts = [
        'from_status' => OrderStatus::class,
        'to_status' => OrderStatus::class,
    ];

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    /**
     * Append a single transition record.
     *
     * The typed actor columns (actor_type/actor_id/source) are written only
     * when their migration has run — the live site is served from this tree,
     * so the code must work both BEFORE and AFTER the developer runs it.
     * `changed_by` (free text) always works and is kept filled either way.
     */
    public static function record(
        SellerOrder $sellerOrder,
        OrderStatus $from,
        OrderStatus $to,
        ?string $changedBy = null,
        ?string $note = null,
        ?OrderActor $actor = null,
        ?int $actorId = null,
        ?string $source = null,
    ): static {
        $attributes = [
            'seller_order_id' => $sellerOrder->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $changedBy,
            'note' => $note,
        ];

        if (RuntimeSchema::hasColumn('status_histories', 'actor_type')) {
            $attributes['actor_type'] = $actor?->value;
            $attributes['actor_id'] = $actorId;
            $attributes['source'] = $source;
        }

        return static::query()->create($attributes);
    }
}
