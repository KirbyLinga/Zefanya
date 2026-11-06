<?php

namespace App\Notifications\Admin;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail alert to the back office when a parcel is cancelled after a second
 * failed pickup attempt.
 */
class PickupFailureException extends Notification
{
    public function __construct(
        public SellerOrder $sellerOrder,
        public int $attempt,
        public string $reason,
        public ?string $notes = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $parcel = $this->sellerOrder;

        $mail = (new MailMessage)
            ->subject('Pickup failure exception - parcel #'.$parcel->id)
            ->greeting('Second pickup attempt failed')
            ->line('Parcel #'.$parcel->id.' ('.$parcel->tracking_number.') was cancelled after '.$this->attempt.' failed pickup attempts.')
            ->line('Seller: '.($parcel->seller?->fullName() ?? 'unknown'))
            ->line('Rider reason: '.$this->reason);

        if (filled($this->notes)) {
            $mail->line('Rider notes: '.$this->notes);
        }

        return $mail->line('Inventory restoration and payment voiding were handled by the order status service.');
    }
}
