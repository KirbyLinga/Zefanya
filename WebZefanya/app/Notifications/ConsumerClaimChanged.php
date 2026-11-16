<?php

namespace App\Notifications;

use App\Models\Buyer\ConsumerClaim;
use Illuminate\Notifications\Notification;

class ConsumerClaimChanged extends Notification
{
    public function __construct(public ConsumerClaim $claim, public string $role, public string $action) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'consumer_claim', 'title' => 'Warranty / Consumer Claim #'.$this->claim->id,
            'message' => 'Claim update: '.str_replace('_', ' ', $this->action).'. Open the case for details.',
            'consumer_claim_id' => $this->claim->id, 'status' => $this->claim->status,
            'seller_order_id' => (int) $this->claim->seller_order_id,
            'url' => route($this->role.'.consumer-claims.show', $this->claim->id)];
    }
}
