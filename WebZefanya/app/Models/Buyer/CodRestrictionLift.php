<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Model;

/** An Admin decision that strikes up to `lifted_at` no longer restrict COD for this buyer and phone. */
class CodRestrictionLift extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['lifted_at' => 'datetime', 'strikes_cleared' => 'integer'];
    }
}
