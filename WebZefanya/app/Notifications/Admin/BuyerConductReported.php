<?php

namespace App\Notifications\Admin;

use App\Models\Shared\BuyerConductReport;
use Illuminate\Notifications\Notification;

class BuyerConductReported extends Notification
{
    public function __construct(private readonly BuyerConductReport $report) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'buyer_conduct_reported',
            'title' => 'Buyer report needs review',
            'message' => 'Seller submitted report #'.$this->report->id.'. No account action has been taken.',
            'url' => route('admin.buyer-reports.show', $this->report),
        ];
    }
}
