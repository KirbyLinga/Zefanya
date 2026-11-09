<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LinehaulBreakdown extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'linehaul_breakdown_reports';

    protected $guarded = ['*'];

    protected $hidden = ['photo_path', 'photo_hash'];

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (! \App\Support\RuntimeSchema::hasTable($this->getTable())) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function delay(): BelongsTo
    {
        return $this->belongsTo(LinehaulTripDelay::class, 'linehaul_trip_delay_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Breakdown evidence cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Breakdown evidence cannot be deleted.'));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Breakdown evidence cannot be edited.');
            }

            public function delete()
            {
                throw new LogicException('Breakdown evidence cannot be deleted.');
            }

            public function forceDelete()
            {
                throw new LogicException('Breakdown evidence cannot be deleted.');
            }
        };
    }
}
