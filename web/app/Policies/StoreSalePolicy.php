<?php

namespace App\Policies;

use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;

class StoreSalePolicy
{
    /** A sale can only be changed by the Seller who runs it. */
    public function manage(Seller $seller, StoreSale $storeSale): bool
    {
        return $storeSale->seller_id === $seller->getKey();
    }
}
