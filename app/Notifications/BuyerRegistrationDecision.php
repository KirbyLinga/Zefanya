<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class BuyerRegistrationDecision extends Notification
{
    use Queueable;

    public function __construct(
        protected string $decision,       // 'approved' | 'rejected'
        protected ?string $reason = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        if ($this->decision === 'approved') {
            return (new MailMessage)
                ->subject('Your Zefanya buyer account has been approved')
                ->greeting("Welcome, {$notifiable->first_name}!")
                ->line('Your buyer registration has been reviewed and approved.')
                ->action('Log in to Zefanya', url('/login'))
                ->line('Thanks for joining Zefanya.');
        }

        return (new MailMessage)
            ->subject('Update on your Zefanya buyer registration')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('Unfortunately, we were unable to approve your buyer registration.')
            ->line("Reason: {$this->reason}")
            ->line('You are welcome to review the details and register again.');
    }
}