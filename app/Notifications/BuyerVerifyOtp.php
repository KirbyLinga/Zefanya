<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyerVerifyOtp extends Notification
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
            ->subject('Your Zefanya verification code')
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Use the 6-digit code below to confirm your email and finish your buyer registration.')
            ->line("**Verification code: {$this->code}**")
            ->line('This code expires in 10 minutes. If it expires, request a new one from the registration form.');
    }
}
