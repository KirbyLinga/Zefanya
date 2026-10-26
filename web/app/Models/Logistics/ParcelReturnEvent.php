<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class ParcelReturnEvent extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metadata' => 'array'];
    }
}
