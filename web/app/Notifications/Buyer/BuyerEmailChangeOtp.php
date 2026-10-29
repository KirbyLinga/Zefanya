<?php

namespace App\Notifications\Buyer;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyerEmailChangeOtp extends Notification
{
    /**
     * @param  string  $code  The RAW 6-digit code (not the hash stored on the buyer).
     */
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new Zefanya email')
            ->greeting('Hi,')
            ->line('Use the 6-digit code below to finish changing the email address on your Zefanya account.')
            ->line("**Verification code: {$this->code}**")
            ->line('This code expires in 10 minutes. Your current email keeps working until the new one is confirmed.');
    }
}
