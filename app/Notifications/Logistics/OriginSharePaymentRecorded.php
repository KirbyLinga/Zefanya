<?php

namespace App\Notifications\Logistics;

use App\Models\Logistics\OriginSharePayout;
use Illuminate\Notifications\Notification;

class OriginSharePaymentRecorded extends Notification
{
    public function __construct(public OriginSharePayout $payout, public bool $reversed = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $amount = 'PHP '.number_format($this->payout->amount_minor / 100, 2);
        $reference = $this->payout->reference;

        return [
            'type' => $this->reversed ? 'origin_share_reversed' : 'origin_share_paid',
            'title' => $this->reversed ? 'Shipping share payment record reversed' : 'Your shipping share payment was recorded',
            'message' => $this->reversed
                ? "The {$amount} payment record (ref {$reference}) was reversed for correction. Its shipping shares are unpaid again; this does not transfer or refund money."
                : "Zefanya recorded {$amount} paid on ".$this->payout->paid_on->format('M j, Y')." for {$this->payout->parcel_count} parcels (ref {$reference}).",
            'url' => route('logistics.earnings.index'),
            'payout_id' => $this->payout->id,
            'amount_minor' => $this->payout->amount_minor,
            'reference' => $reference,
            'paid_on' => $this->payout->paid_on->toDateString(),
        ];
    }
}
