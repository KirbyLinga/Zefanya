<?php

namespace App\Policies;

use App\Models\Buyer\Buyer;
use App\Models\Order;

/**
 * Authorization for BUYER order actions: a buyer may only view/confirm THEIR
 * own orders.
 */
class BuyerOrderPolicy
{
    public function view(Buyer $buyer, Order $order): bool
    {
        return $order->buyer_id === $buyer->getKey();
    }

    public function confirmReceived(Buyer $buyer, Order $order): bool
    {
        return $this->view($buyer, $order);
    }
}
