<?php

namespace App\Notifications\Buyer;

use App\Support\EmailMask;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice to the OLD address once a change completes, so a change the
 * owner did not make does not go unnoticed.
 */
class BuyerEmailChanged extends Notification
{
    public function __construct(public string $newEmail, public string $name) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Zefanya email was changed')
            ->greeting("Hi {$this->name},")
            ->line('The email on your Zefanya account was changed to '.EmailMask::mask($this->newEmail).'. This address can no longer sign in.')
            ->line('If you did not make this change, please contact Zefanya support right away.');
    }
}
