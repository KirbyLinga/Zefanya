<?php

namespace App\Notifications\Logistics;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class LogisticsRegistrationOtp extends Notification
{
    // Not queued — same reasoning as SellerRegistrationOtp.

    public function __construct(public string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Zefanya logistics verification code')
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Use this code to verify your email and continue your logistics registration:')
            ->line(new HtmlString(
                '<h1 style="letter-spacing: 4px; font-size: 32px;">'.$this->otp.'</h1>'
            ))
            ->line('This code expires in 10 minutes.')
            ->line('If you didn\'t request this, you can safely ignore this email.');
    }
}
