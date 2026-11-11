<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A signed, audited change to a paid lost-parcel compensation. Append-only. */
class ParcelLossAdjustment extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compensation adjustments cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Compensation adjustments cannot be deleted.'));
    }

    public function lossCase(): BelongsTo
    {
        return $this->belongsTo(ParcelLossCase::class, 'parcel_loss_case_id');
    }
}
