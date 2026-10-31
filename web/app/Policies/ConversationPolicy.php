<?php

namespace App\Policies;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Shared\Conversation;

class ConversationPolicy
{
    public function view(Buyer|Seller $user, Conversation $conversation): bool
    {
        if ($user instanceof Buyer && $user->id === $conversation->buyer_id) {
            return true;
        }

        if ($user instanceof Seller && $user->id === $conversation->seller_id) {
            return true;
        }

        return false;
    }

    public function update(Buyer|Seller $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function create(Buyer $buyer): bool
    {
        return true;
    }
}
