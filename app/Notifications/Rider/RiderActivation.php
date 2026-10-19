<?php

namespace App\Notifications\Rider;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiderActivation extends Notification
{
    public function __construct(public string $url) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activate your Zefanya rider account')
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Your rider account is ready. Set your password within 24 hours to activate it.')
            ->action('Set password', $this->url)
            ->line('If you did not expect this, you can safely ignore this email.');
    }
}
