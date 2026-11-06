<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** In-app alert plus email. The database row never stores the code. */
class DeliveryOtpCode extends Notification
{
    public function __construct(public SellerOrder $parcel, public string $code) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tracking = $this->parcel->tracking_number ?: 'your order';

        return (new MailMessage)
            ->subject('Your Zefanya delivery code')
            ->line('Give this code to the rider to confirm delivery of '.$tracking.'.')
            ->line('**Delivery code: '.$this->code.'**')
            ->line('It expires in 10 minutes. The same code is on your order page while you are signed in.')
            ->line('If you did not expect a delivery, do not share this code.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'delivery_code',
            'seller_order_id' => $this->parcel->id,
            'tracking_number' => $this->parcel->tracking_number,
            'message' => 'Your rider needs your delivery code. Open this order to see it.',
        ];
    }
}
