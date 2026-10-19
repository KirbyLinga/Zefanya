<?php

namespace App\Notifications\Rider;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

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
            ->subject('Your Zefanya Rider verification code')
            ->greeting('Hi '.$notifiable->fullName().',')
            ->line('Use this code to verify your email and continue your Rider registration:')
            ->line(new HtmlString('<h1 style="letter-spacing: 4px; font-size: 32px;">'.e($this->otp).'</h1>'))
            ->line('This code expires in 10 minutes.')
            ->line('After verification, your application will await your selected Logistics provider\'s approval.');
    }
}
