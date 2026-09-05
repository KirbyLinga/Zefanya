<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SellerRegistrationDecision extends Notification
{
    // Not queued — same reasoning as BuyerRegistrationDecision.

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
                ? 'Your seller registration has been approved'
                : 'Your seller registration was not approved');

        if ($this->decision === 'approved') {
            return $message
                ->greeting("Welcome, {$notifiable->fullName()}!")
                ->line("Your seller account for \"{$notifiable->business_name}\" has been approved.")
                ->action('Log in', url('/login'));
        }

        return $message
            ->greeting("Hi {$notifiable->fullName()},")
            ->line('Unfortunately your seller registration was not approved.')
            ->when($this->reason, fn ($msg) => $msg->line("Reason: {$this->reason}"))
            ->line('You are welcome to submit a new registration with corrected information.');
    }
}