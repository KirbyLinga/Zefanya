<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeliveryOtpCode extends Notification
{
    public function __construct(public SellerOrder $parcel, public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Zefanya delivery confirmation code')
            ->greeting('Hi '.$notifiable->fullName().',')
            ->line('Delivery for parcel '.($this->parcel->tracking_number ?? '#'.$this->parcel->id).' is ready to be confirmed.')
            ->line('**Delivery code: '.$this->code.'**')
            ->line('This code expires in 10 minutes. Share it with the assigned rider only after receiving your parcel and checking any cash payment.')
            ->line('A newly requested code replaces the previous code.');
    }
}
