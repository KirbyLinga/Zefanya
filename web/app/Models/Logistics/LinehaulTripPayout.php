<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LinehaulTripPayout extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Courier payout records are immutable.'));
        static::deleting(fn () => throw new LogicException('Courier payout records are immutable.'));
    }
}
