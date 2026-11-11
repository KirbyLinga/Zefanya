<?php

namespace App\Notifications\Admin;

use App\Models\Logistics\ParcelLossCase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/** A hub scanned a parcel Admin had already confirmed lost. Admin decides what happens to it. */
class LostParcelFound extends Notification
{
    public function __construct(public ParcelLossCase $case, public string $tracking) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lost parcel found - '.$this->tracking)
            ->greeting('A parcel confirmed lost has been found')
            ->line("A hub's verified scan recorded parcel {$this->tracking} as found. The order stays closed as lost and no stock was restored.")
            ->line('Decide what happens to the parcel on the Lost parcels page.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lost_parcel_found',
            'title' => 'Lost parcel found',
            'message' => "Parcel {$this->tracking} was scanned at a hub after being confirmed lost. Decide what happens to it.",
            'url' => Route::has('admin.lost-parcels.index') ? route('admin.lost-parcels.index') : null,
            'case_id' => $this->case->id,
        ];
    }
}
