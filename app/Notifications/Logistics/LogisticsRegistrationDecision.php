<?php

namespace App\Notifications\Logistics;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LogisticsRegistrationDecision extends Notification
{
    // Not queued — same reasoning as SellerRegistrationDecision.

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
                ? 'Your logistics registration has been approved'
                : 'Your logistics registration was not approved');

        if ($this->decision === 'approved') {
            return $message
                ->greeting("Welcome, {$notifiable->fullName()}!")
                ->line("Your logistics account for \"{$notifiable->business_name}\" has been approved.")
                ->action('Sign in', route('login'));
        }

        return $message
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Unfortunately your logistics registration was not approved.')
            ->when($this->reason, fn ($msg) => $msg->line("Reason: {$this->reason}"))
            ->line('You are welcome to submit a new registration with corrected information.');
    }
}
