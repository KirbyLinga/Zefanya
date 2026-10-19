<?php

namespace App\Notifications\Rider;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiderApplicationDecisionNotification extends Notification
{
    public function __construct(public string $decision, public ?string $reason, public string $providerName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Your Zefanya Rider application was '.$this->decision)
            ->greeting('Hi '.$notifiable->fullName().',')
            ->line($this->providerName.' has '.$this->decision.' your Rider application.');
        if ($this->decision === 'approved') {
            $mail->line('Your verified Rider account is approved. Sign in with the password you chose during registration.')
                ->action('Sign in', route('login'));
        } elseif ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail;
    }
}
