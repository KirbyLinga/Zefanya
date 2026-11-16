<?php

namespace App\Notifications\Admin;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail notice when an admin suspends or reactivates an approved account.
 * Database-only would be useless — suspended users cannot open the panel.
 */
class AccountStatusChanged extends Notification
{
    /**
     * @param  'suspended'|'approved'  $status
     */
    public function __construct(public string $status) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = method_exists($notifiable, 'fullName')
            ? $notifiable->fullName()
            : (string) ($notifiable->email ?? 'there');

        if ($this->status === 'suspended') {
            return (new MailMessage)
                ->subject('Your Zefanya account has been suspended')
                ->greeting("Hi {$name},")
                ->line('An administrator has suspended your account. You will not be able to sign in until it is reactivated.')
                ->line('If you believe this is a mistake, please contact support.');
        }

        return (new MailMessage)
            ->subject('Your Zefanya account has been reactivated')
            ->greeting("Hi {$name},")
            ->line('An administrator has reactivated your account. You can sign in again.')
            ->action('Log in', route('home', ['login' => 1]));
    }
}
