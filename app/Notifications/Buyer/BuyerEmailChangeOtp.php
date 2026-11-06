<?php

namespace App\Notifications\Buyer;

use App\Models\Buyer\Buyer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the NEW address, on demand: it belongs to no account until confirmed.
 */
class BuyerEmailChangeOtp extends Notification
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
            ->subject('Confirm your new Zefanya email')
            ->greeting("Hi {$this->name},")
            ->line('Use the 6-digit code below to confirm this as your new Zefanya email address.')
            ->line("**Verification code: {$this->code}**")
            ->line('This code expires in '.Buyer::OTP_TTL_MINUTES.' minutes. Your current email keeps working until you confirm. If you did not ask for this, you can ignore this message.');
    }
}
