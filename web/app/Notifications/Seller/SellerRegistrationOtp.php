<?php

namespace App\Notifications\Seller;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class SellerRegistrationOtp extends Notification
{
    // Send synchronously (not queued) — registration OTP must arrive immediately.

    public function __construct(public string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Zefanya seller verification code')
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Use this code to verify your email and continue your seller registration:')
            ->line(new HtmlString(
                '<h1 style="letter-spacing: 4px; font-size: 32px;">'.$this->otp.'</h1>'
            ))
            ->line('This code expires in 10 minutes.')
            ->line('If you didn\'t request this, you can safely ignore this email.');
    }
}
