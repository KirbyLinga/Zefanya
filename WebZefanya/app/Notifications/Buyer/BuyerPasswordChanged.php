<?php

namespace App\Notifications\Buyer;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyerPasswordChanged extends Notification
{
    public function __construct(public string $name) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Zefanya password was changed')
            ->greeting("Hi {$this->name},")
            ->line('Your Zefanya password was just changed, and other devices have been signed out.')
            ->line('If this was not you, reset your password right away and contact Zefanya support.');
    }
}
