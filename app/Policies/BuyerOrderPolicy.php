<?php

namespace App\Policies;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for BUYER order actions: a buyer may only view/confirm THEIR
 * own orders.
 */
class BuyerOrderPolicy
{
    public function view(Buyer $buyer, Order $order): Response
    {
        return $order->buyer_id === $buyer->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function confirmReceived(Buyer $buyer, Order $order): Response
    {
        return $this->view($buyer, $order);
    }
}
