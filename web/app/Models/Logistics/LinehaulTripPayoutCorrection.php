<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LinehaulTripPayoutCorrection extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Courier payout corrections are immutable.'));
        static::deleting(fn () => throw new LogicException('Courier payout corrections are immutable.'));
    }
}
