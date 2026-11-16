<?php

namespace App\Policies;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;

class CheckoutPolicy
{
    public function quote(Buyer $buyer, ?BuyerAddress $address = null): bool
    {
        return $buyer->exists && ($address === null || (int) $address->buyer_id === (int) $buyer->id);
    }
}
