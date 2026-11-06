<?php

namespace App\Notifications\Logistics;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PickupRequested extends Notification
{
    public function __construct(
        public $sellerOrder,
        public $carrierProviderId
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // TODO: push/SMS delivery is not implemented yet; database + mail only.
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $parcel = $this->sellerOrder instanceof SellerOrder ? $this->sellerOrder : null;
        $tracking = $parcel?->tracking_number ?? ($parcel !== null ? "Parcel #{$parcel->id}" : 'Parcel');

        return [
            'type' => 'pickup_requested',
            'title' => 'Pickup requested',
            'message' => "{$tracking}: a seller has marked this parcel ready for pickup.",
            'seller_order_id' => $parcel?->id,
            'carrier_provider_id' => $this->carrierProviderId,
            'url' => route('logistics.incoming-parcels.index', ['highlight' => $parcel?->id]),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $parcel = $this->sellerOrder instanceof SellerOrder ? $this->sellerOrder : null;
        $tracking = $parcel?->tracking_number ?? ($parcel !== null ? "parcel #{$parcel->id}" : 'parcel');

        return (new MailMessage)
            ->subject("Pickup requested — {$tracking}")
            ->greeting('New pickup request')
            ->line("A seller has marked {$tracking} as ready for pickup.")
            ->action('View incoming parcels', route('logistics.incoming-parcels.index', ['highlight' => $parcel?->id]));
    }
}
