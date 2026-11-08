<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LinehaulTripDelay extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['fingerprint'];

    protected function casts(): array
    {
        return ['new_eta' => 'datetime', 'offer_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Courier delay reports cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Courier delay reports cannot be deleted.'));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Courier delay reports cannot be edited.');
            }

            public function delete()
            {
                throw new LogicException('Courier delay reports cannot be deleted.');
            }

            public function forceDelete()
            {
                throw new LogicException('Courier delay reports cannot be deleted.');
            }
        };
    }
}
