<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ConsumerClaimUpdate extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consumer claim history is append-only.'));
        static::deleting(fn () => throw new LogicException('Consumer claim history is append-only.'));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Consumer claim history is append-only.');
            }

            public function delete()
            {
                throw new LogicException('Consumer claim history is append-only.');
            }

            public function forceDelete()
            {
                throw new LogicException('Consumer claim history is append-only.');
            }
        };
    }
}
