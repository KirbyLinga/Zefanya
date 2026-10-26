<?php

namespace App\Notifications\Rider;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiderRegistrationOtp extends Notification
{
    public function __construct(public string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Zefanya rider verification code')
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Enter this code on the registration screen to verify your email:')
            ->line($this->otp)
            ->line('This code expires in 10 minutes.')
            ->line('After verification, your application requires Logistics approval.')
            ->line('If you did not request this, you can ignore this email.');
    }
}
