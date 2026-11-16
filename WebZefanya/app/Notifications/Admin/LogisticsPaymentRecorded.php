<?php

namespace App\Notifications\Admin;

use App\Models\Logistics\LogisticsRemittance;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Admin has no notification bell, so this is by email; the dashboard work list shows it too. */
class LogisticsPaymentRecorded extends Notification
{
    public function __construct(public LogisticsRemittance $remittance) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Logistics payment to confirm: ₱'.number_format($this->remittance->amount_minor / 100, 2))
            ->line(($this->remittance->provider?->business_name ?? 'A Logistics company').' recorded a payment to Zefanya.')
            ->line('Amount: ₱'.number_format($this->remittance->amount_minor / 100, 2))
            ->line('Reference: '.$this->remittance->reference)
            ->line('Confirm it once the money has arrived. Sellers on these parcels cannot be paid until then.')
            ->action('Review payment', route('admin.logistics-remittances.index'));
    }
}
