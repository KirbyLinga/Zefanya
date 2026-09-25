<?php

namespace App\Notifications\Buyer;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyerRegistrationDecision extends Notification
{
    // Not queued, same reasoning as BuyerVerifyEmail: low volume (one per
    // admin decision), and you don't currently have a queue worker running
    // in production, so queuing it means it silently never sends.

    /**
     * @param  'approved'|'rejected'  $decision
     */
    public function __construct(
        public string $decision,
        public ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->decision === 'approved'
                ? 'Your buyer registration has been approved'
                : 'Your buyer registration was not approved');

        if ($this->decision === 'approved') {
            return $message
                ->greeting("Welcome, {$notifiable->fullName()}!")
                ->line('Your buyer account has been approved. You can now log in and start shopping.')
                ->action('Log in', url('/login'));
        }

        return $message
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Unfortunately your buyer registration was not approved.')
            ->when($this->reason, fn ($msg) => $msg->line("Reason: {$this->reason}"))
            ->line('You are welcome to submit a new registration with corrected information.');
    }
}
