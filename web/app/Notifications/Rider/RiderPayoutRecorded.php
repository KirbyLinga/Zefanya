<?php

namespace App\Notifications\Rider;

use App\Models\Rider\RiderPayout;

class RiderPayoutRecorded extends RiderNotification
{
    public function __construct(public RiderPayout $payout, public bool $reversed = false) {}

    public function dedupeKey(): string
    {
        return 'rider-payout-'.($this->reversed ? 'reversed:' : 'paid:').$this->payout->id;
    }

    protected function payload(): array
    {
        $amount = 'PHP '.number_format($this->payout->amount_minor / 100, 2);
        $reference = $this->payout->reference;

        return [
            'type' => $this->reversed ? 'rider_payout_reversed' : 'rider_payout_paid',
            'title' => $this->reversed ? 'Payment record reversed' : 'Your pay was recorded',
            'message' => $this->reversed
                ? "The {$amount} payment record (ref {$reference}) was reversed for correction. Its jobs are unpaid again; this does not transfer or refund money."
                : "Your company recorded {$amount} paid on ".$this->payout->paid_on->format('M j, Y')." for {$this->payout->earning_count} jobs (ref {$reference}).",
            'url' => route('rider.dashboard'),
            'payout_id' => $this->payout->id,
            'amount_minor' => $this->payout->amount_minor,
            'reference' => $reference,
            'paid_on' => $this->payout->paid_on->toDateString(),
        ];
    }
}
