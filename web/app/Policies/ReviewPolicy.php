<?php

namespace App\Policies;

use App\Models\Buyer\Buyer;

class ReviewPolicy
{
    public function viewAny(Buyer $buyer): bool
    {
        return $buyer->exists;
    }
}
