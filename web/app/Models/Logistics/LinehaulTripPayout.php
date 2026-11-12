<?php

namespace App\Models\Logistics;

use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class LinehaulTripPayout extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_on' => 'date'];
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(LinehaulTripPayoutCorrection::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(LinehaulTripPayoutCorrection::class, 'reversed_payout_id');
    }

    public function recordedReference(): string
    {
        return RuntimeSchema::hasTable('linehaul_trip_payout_corrections')
            ? ($this->corrections()->whereNull('reversed_payout_id')->latest('id')->value('reference') ?? $this->reference)
            : $this->reference;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Courier payout records are immutable.'));
        static::deleting(fn () => throw new LogicException('Courier payout records are immutable.'));
    }
}
