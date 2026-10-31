<?php

namespace App\Notifications\Buyer;

use App\Models\Buyer\Buyer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyerPasswordChangeOtp extends Notification
{
    /**
     * @param  string  $code  The RAW 6-digit code (not the hash stored on the buyer).
     */
    public function __construct(public string $code, public string $name) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new Zefanya password')
            ->greeting("Hi {$this->name},")
            ->line('Use the 6-digit code below to confirm your new Zefanya password.')
            ->line("**Verification code: {$this->code}**")
            ->line('This code expires in '.Buyer::OTP_TTL_MINUTES.' minutes. Your current password keeps working until you confirm.')
            ->line('If you did not ask for this, someone may know your password. Do not share this code, and change your password.');
    }
}
