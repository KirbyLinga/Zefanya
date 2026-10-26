<?php

namespace App\Policies;

use App\Models\Seller\Seller;
use App\Models\SellerOrder;

/**
 * Authorization for seller_order actions.
 *
 * The seller guard is a separate auth guard, so the policy methods receive
 * whatever model is authenticated on it (Seller::class) — asserted here via
 * class checks so a cross-guard call resolves to deny, never to an error.
 */
class SellerOrderPolicy
{
    /**
     * The owning seller may view and manage (mark ready) their slice.
     */
    public function view(Seller $seller, SellerOrder $sellerOrder): bool
    {
        return $this->manage($seller, $sellerOrder);
    }

    public function markReady(Seller $seller, SellerOrder $sellerOrder): bool
    {
        return $this->manage($seller, $sellerOrder);
    }

    public function decline(Seller $seller, SellerOrder $sellerOrder): bool
    {
        return $this->manage($seller, $sellerOrder);
    }

    /**
     * Single rule behind view/manage abilities: the seller_order belongs to
     * the acting seller's shop.
     */
    private function manage(Seller $seller, SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->seller_id === $seller->getKey();
    }
}
