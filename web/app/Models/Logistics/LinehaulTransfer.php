<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

class LinehaulTransfer extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'offer_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Courier transfers cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Courier transfers cannot be deleted.'));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Courier transfers cannot be edited.');
            }

            public function delete()
            {
                throw new LogicException('Courier transfers cannot be deleted.');
            }

            public function forceDelete()
            {
                throw new LogicException('Courier transfers cannot be deleted.');
            }
        };
    }
}
