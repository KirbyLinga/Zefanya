<?php

namespace App\Notifications\Logistics;

use App\Models\Rider;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when suspending/deactivating a rider leaves parcels that must be
 * reassigned. The parcels themselves are only flagged, never moved through
 * the status graph automatically.
 */
class RiderAssignmentsNeedReassignment extends Notification
{
    public function __construct(
        public Rider $rider,
        public int $parcelCount,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'rider_reassignment_needed',
            'title' => 'Rider unavailable ? reassignment needed',
            'message' => "{$this->rider->fullName()}: {$this->parcelCount} active parcel(s) need reassignment.",
            'rider_id' => $this->rider->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Rider unavailable — parcels need reassignment')
            ->greeting('Rider assignment update')
            ->line("{$this->rider->fullName()} is no longer available for new assignments.")
            ->line("{$this->parcelCount} active parcel(s) assigned to this rider are now flagged for reassignment.")
            ->action('Review parcels', url('/logistics/incoming-parcels'))
            ->line('Reassign them from the existing pickup/sorting screens so the delivery status history is preserved.');
    }
}
